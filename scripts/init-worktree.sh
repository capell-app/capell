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
# Finally it gives the worktree its own node_modules/, which composer preflight
# needs for the prettier and eslint stages. That one is a copy-on-write clone
# rather than a symlink, because npm ci deletes the directory before installing
# and would take the primary checkout's copy with it.
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
PROBE_CLASS='Capell\Core\Enums\Concerns\HasEnumOptions'

if ! command -v php >/dev/null 2>&1; then
    echo >&2
    echo "FAILED: no 'php' on PATH, so the vendor/ layout cannot be verified." >&2
    echo "Removing the unverified vendor/ rather than leaving a possibly poisoned one." >&2
    rm -rf vendor
    exit 1
fi

# Do NOT let this run under set -e without capturing why it failed. A PHP fatal
# exits 255, which would otherwise kill this script with a bare, unexplained
# 255 and no clue that PHP was even involved.
probe_stderr=$(mktemp)
set +e
resolved=$(CAPELL_PROBE_CLASS="$PROBE_CLASS" php -r '
    require "vendor/autoload.php";
    echo (new ReflectionClass(getenv("CAPELL_PROBE_CLASS")))->getFileName();
' 2>"$probe_stderr")
probe_status=$?
set -e

if [ "$probe_status" -ne 0 ]; then
    echo >&2
    echo "FAILED: the vendor/ verification probe could not run (php exit $probe_status)." >&2
    echo "Probe class: $PROBE_CLASS" >&2
    if [ -s "$probe_stderr" ]; then
        echo "PHP reported:" >&2
        sed 's/^/  /' "$probe_stderr" >&2
    fi
    echo >&2
    echo "Common causes: vendor/autoload.php is broken, the probe class was renamed" >&2
    echo "or removed, or this php cannot load the autoloader." >&2
    echo "Removing the unverified vendor/ - run 'composer install' here instead." >&2
    rm -f "$probe_stderr"
    rm -rf vendor
    exit 1
fi
rm -f "$probe_stderr"

if [ -z "$resolved" ]; then
    echo >&2
    echo "FAILED: the probe returned no path for $PROBE_CLASS." >&2
    echo "Removing the unverified vendor/ - run 'composer install' here instead." >&2
    rm -rf vendor
    exit 1
fi

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

# ---------------------------------------------------------------------------
# node_modules. `composer preflight` runs prettier and eslint, so a worktree
# without node_modules/ fails the entire preflight before a single PHP stage
# runs — the error names npm but reads like the worktree itself is broken.
#
# This is deliberately NOT a symlink. vendor/ can share packages because they
# are only ever read; node_modules is different. `npm ci` DELETES the directory
# before it installs, so one npm command in one worktree would wipe the primary
# checkout's node_modules out from under every other session using it. Node
# also resolves symlinks to their realpath, so tools would load their own
# dependencies from the primary tree — the same wrong-tree class of bug this
# script exists to prevent for vendor/.
#
# A copy-on-write clone gives isolation at symlink speed. On APFS the clone
# shares blocks with the primary until something writes, so it costs about a
# second and almost no disk, and any later npm write stays inside this
# worktree.
# ---------------------------------------------------------------------------
if [ -e node_modules ]; then
    echo "node_modules/ already exists, leaving it alone."
elif [ ! -d "$PRIMARY_ROOT/node_modules" ]; then
    echo "Primary checkout has no node_modules/, so there is nothing to clone."
    echo "Run 'npm ci' here if you need the prettier and eslint preflight stages."
elif cp -Rc "$PRIMARY_ROOT/node_modules" node_modules 2>/dev/null; then
    echo "Cloned node_modules/ ($(find node_modules -maxdepth 1 -mindepth 1 | wc -l | tr -d ' ') entries, copy-on-write, isolated from the primary)."
else
    rm -rf node_modules
    echo "Copy-on-write clone unavailable (not an APFS volume?), running npm ci instead."
    npm ci
fi

cat <<'EOF'

Done. Remember that this repo's tooling needs an explicit memory limit:

  composer test:unit
  php -d memory_limit=1G vendor/bin/pest --compact --configuration=phpunit.xml <path>
  php -d memory_limit=2G vendor/bin/phpstan analyse --no-progress <path>

Parts of vendor/ are shared with the primary checkout. Composer scripts are safe,
but never run composer install/require/update/remove from this worktree — dependency
mutations can write through the shared package symlinks.

node_modules/ is a copy-on-write clone, not a symlink, so npm is safe here: an
npm install or npm ci in this worktree cannot reach the primary checkout.

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
