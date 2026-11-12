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
# This script builds an owned vendor/ instead:
#
#   vendor/composer/     real copy  -> $baseDir resolves to THIS worktree
#   vendor/autoload.php  real copy
#   vendor/bin/          real copy
#   vendor/<pkg>/<name>  real copy  -> every package is a copy-on-write clone
#
# Nothing in vendor/ points at the primary checkout, so a later change to the
# primary's vendor/ cannot leave this worktree with stale or wrong dependencies,
# and Testbench's skeleton (which creates a vendor link and mutable files) stays
# private. Test processes use disposable, isolated skeleton copies as well.
# scripts/check-vendor-integrity.php refuses any package symlinked into another
# checkout.
#
# It then verifies the result and refuses to leave a poisoned vendor/ behind.
#
# Finally it gives the worktree its own node_modules/, which composer preflight
# needs for the prettier and eslint stages. That one is a copy-on-write clone
# rather than a symlink, because npm ci deletes the directory before installing
# and would take the primary checkout's copy with it.
#
# The result holds no host-absolute links into the primary checkout, so it does
# not depend on the primary being mounted. --host-only is accepted for older
# callers and has no effect.
#
# Usage:  bash scripts/init-worktree.sh [--force] [--host-only]

set -euo pipefail

FORCE=0
HOST_ONLY=0

for arg in "$@"; do
    case "$arg" in
        --force) FORCE=1 ;;
        --host-only) HOST_ONLY=1 ;;
        *)
            echo "Unknown option: $arg" >&2
            echo "Usage: bash scripts/init-worktree.sh [--force] [--host-only]" >&2
            exit 2
            ;;
    esac
done

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

if ! command -v composer >/dev/null 2>&1; then
    echo "Composer is required to generate this checkout's source mappings." >&2
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

echo "Primary : $PRIMARY_ROOT"
echo "Worktree: $WORKTREE_ROOT"

mkdir vendor
cp -R "$PRIMARY_ROOT/vendor/composer" vendor/composer
cp "$PRIMARY_ROOT/vendor/autoload.php" vendor/autoload.php
cp -R "$PRIMARY_ROOT/vendor/bin" vendor/bin

# Top-level metadata files beside the package directories (such as Pest's
# pest-plugins.json plugin cache) belong to the dependency set too. Without
# them Pest loads no plugins, so --parallel and the Laravel helpers vanish.
# Hidden files count too; unmatched patterns stay literal and fail the -f test.
for vendor_file in "$PRIMARY_ROOT"/vendor/* "$PRIMARY_ROOT"/vendor/.[!.]* "$PRIMARY_ROOT"/vendor/..?*; do
    [ -f "$vendor_file" ] || continue
    case "$(basename "$vendor_file")" in autoload.php) continue ;; esac
    cp "$vendor_file" "vendor/$(basename "$vendor_file")"
done

# Third-party packages are real trees owned by this worktree, never symlinks into
# the primary checkout. A symlink goes stale or wrong the moment the primary's
# vendor/ moves on (a missing class mid-run), and Pest's architecture presets
# follow it out of vendor/ and treat dependency code as application source.
# On APFS `cp -Rc` clones the blocks, so this costs seconds and almost no disk;
# elsewhere it falls back to a plain copy.
clone_flag=-Rc
probe_dir=$(mktemp -d)
if ! cp -Rc "$PRIMARY_ROOT/vendor/composer" "$probe_dir/probe" 2>/dev/null; then
    clone_flag=-R
fi
rm -rf "$probe_dir"

copied=0
for vendor_dir in "$PRIMARY_ROOT"/vendor/*/; do
    vendor_name=$(basename "$vendor_dir")
    case "$vendor_name" in composer | bin) continue ;; esac

    mkdir -p "vendor/$vendor_name"
    for package_dir in "$vendor_dir"*/; do
        [ -d "$package_dir" ] || continue
        package_name=$(basename "$package_dir")
        cp "$clone_flag" "${package_dir%/}" "vendor/$vendor_name/$package_name"
        copied=$((copied + 1))
    done
done

# A copied skeleton may contain a link created by an earlier worktree. Remove
# only the copied link; Testbench will create a local one when it needs it.
if [ -L vendor/orchestra/testbench-core/laravel/vendor ]; then
    rm vendor/orchestra/testbench-core/laravel/vendor
fi

echo "Copied $copied packages ($(du -sh vendor | cut -f1))."

# Copied metadata can predate this checkout's manifest. Regenerate only the
# owned loader; disabled scripts/plugins cannot mutate shared dependencies.
if ! composer dump-autoload --dev --no-interaction --no-scripts --no-plugins; then
    echo "Failed to generate this checkout's autoloader; removing the new vendor/." >&2
    rm -rf vendor
    exit 1
fi

# ---------------------------------------------------------------------------
# Verify. A wrong answer here means the suite would test the primary checkout,
# so this is a hard failure, not a warning.
# ---------------------------------------------------------------------------
PROBE_CLASS='Capell\Core\Enums\ImageSourceType'

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
# This is deliberately NOT a symlink. `npm ci` DELETES the directory
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

# ---------------------------------------------------------------------------
# Linked sibling packages. package.json links the screenshot tools with
# "file:../capell-screenshot-tools", so npm leaves a RELATIVE link in
# node_modules/@capell-app. Cloned into a worktree under .capell-wt, that link
# resolves against the wrong directory and dangles, which breaks the screenshot
# runner. Re-point each such link at the absolute location the primary's link
# resolves to. They are siblings of the primary, not part of it, so nothing here
# reaches into the primary checkout's own tree.
# ---------------------------------------------------------------------------
if [ -d node_modules/@capell-app ]; then
    for linked in node_modules/@capell-app/*; do
        [ -L "$linked" ] || continue
        [ -e "$linked" ] && continue
        primary_target=$(cd "$PRIMARY_ROOT/node_modules/@capell-app/$(basename "$linked")" 2>/dev/null && pwd -P) || primary_target=""
        if [ -n "$primary_target" ] && [ -e "$primary_target" ] && [ "${primary_target#"$PRIMARY_ROOT"/}" = "$primary_target" ]; then
            ln -sfn "$primary_target" "$linked"
            echo "Re-pointed $linked at $primary_target."
        else
            echo "WARNING: $linked dangles and its sibling package could not be located; run npm ci here." >&2
        fi
    done
fi

cat <<'EOF'

Done. This vendor/ is owned by this worktree: every package is a copy-on-write
clone, so nothing in it points at the primary checkout and the primary's later
vendor changes cannot reach it.

Remember that this repo's tooling needs an explicit memory limit:

  composer test:unit
  php -d memory_limit=1G vendor/bin/pest --compact --configuration=phpunit.xml <path>
  php -d memory_limit=2G vendor/bin/phpstan analyse --no-progress <path>

The vendor/ copy is a snapshot of the primary at provisioning time. After the
primary's dependencies change, re-run `bash scripts/init-worktree.sh --force`
here.

node_modules/ is a copy-on-write clone, not a symlink, so npm is safe here: an
npm install or npm ci in this worktree cannot reach the primary checkout.

Confirm the classes you changed resolve inside this worktree before believing a
result:

  php -r 'require "vendor/autoload.php";
    echo (new ReflectionClass("Your\\Changed\\Class"))->getFileName(), PHP_EOL;'
EOF
