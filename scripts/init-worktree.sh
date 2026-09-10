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
# This script builds a complete copy-on-write clone instead. APFS shares the
# unchanged blocks with the primary checkout, but every path has a worktree
# realpath and writes stay isolated. The generated autoloader is then rebuilt
# here so its base directory cannot point back to the primary checkout.
#
# It then verifies the result and refuses to leave a poisoned vendor/ behind.
#
# Finally it gives the worktree its own node_modules/, which composer preflight
# needs for the prettier and eslint stages. That one is a copy-on-write clone
# rather than a symlink, because npm ci deletes the directory before installing
# and would take the primary checkout's copy with it.
#
# It is HOST-ONLY. The clone is made at the host path and is not the dependency
# install used by this repo's Docker container, which mounts the worktree at
# /home/capell/current. If you run tooling through ./capell, run a real
# `./capell composer install` instead; this script refuses to initialise a
# container worktree unless you pass --host-only.
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

cd "$WORKTREE_ROOT"

# ---------------------------------------------------------------------------
# Docker tooling must use a container-local Composer install. The container
# bind-mounts this worktree at /home/capell/current, and the host's Composer
# cache/autoload assumptions are not the container's dependency environment.
# A host clone may therefore be correct on macOS and still be the wrong vendor
# tree for ./capell.
#
#   require(.../symfony/deprecation-contracts/function.php): Failed to open stream
#
# which reads like a corrupt install rather than a wrong runtime environment,
# and it happens before a single test runs. Refuse up front rather than build a
# vendor tree that is not authoritative for the container.
# ---------------------------------------------------------------------------
if [ "$HOST_ONLY" -ne 1 ] && [ -f docker-compose.yml ] && grep -q '\./\?:/home/capell/current' docker-compose.yml; then
    cat >&2 <<'EOF'
REFUSING: this repository runs its PHP tooling inside Docker.

scripts/init-worktree.sh prepares a host-only vendor/ clone. The container
mounts this worktree at /home/capell/current and must resolve every dependency
from that container path.

Do this instead, in this worktree:

    ./capell up
    ./capell composer install

That is a real, self-contained vendor/ that works in the container. With a warm
Composer cache it takes well under two minutes.

If you genuinely intend to run PHP on the HOST and never in the container,
re-run with --host-only. The resulting vendor/ is not the container install.
EOF
    exit 1
fi

if ! cmp -s "$PRIMARY_ROOT/composer.json" composer.json ||
   ! cmp -s "$PRIMARY_ROOT/composer.lock" composer.lock; then
    echo "Dependency manifests differ from the primary checkout; refusing to clone vendor/." >&2
    echo "Run a real composer install in this worktree instead." >&2
    exit 1
fi

if ! command -v composer >/dev/null 2>&1; then
    echo "FAILED: 'composer' is required to regenerate the cloned autoloader." >&2
    exit 1
fi

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

if cp -Rc "$PRIMARY_ROOT/vendor" vendor 2>/dev/null; then
    echo "Cloned vendor/ with copy-on-write ($(du -sh vendor | cut -f1)); dependency writes stay in this worktree."
else
    echo "Copy-on-write clone unavailable; making a complete private vendor/ copy."
    cp -R "$PRIMARY_ROOT/vendor" vendor
fi

vendor_symlink=$(find vendor -type l -print -quit)
if [ -n "$vendor_symlink" ]; then
    echo "FAILED: the cloned vendor/ contains a symlink: $vendor_symlink" >&2
    echo "Install a real vendor/ in the primary checkout before creating worktrees." >&2
    rm -rf vendor
    exit 1
fi

composer dump-autoload --no-interaction --no-scripts --optimize

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

Done. This vendor/ is a HOST-ONLY copy-on-write clone. It is isolated from the
primary checkout, but it is not the dependency install used by ./capell's
container. Run `./capell composer install` in this worktree before Docker runs.

Remember that this repo's tooling needs an explicit memory limit:

  composer test:unit
  php -d memory_limit=1G vendor/bin/pest --compact --configuration=phpunit.xml <path>
  php -d memory_limit=2G vendor/bin/phpstan analyse --no-progress <path>

The vendor/ blocks initially share storage with the primary checkout through
APFS copy-on-write, but the paths and writes belong to this worktree. Composer
install/require/update/remove are safe here while composer.json and
composer.lock remain identical to the primary checkout.

node_modules/ is a copy-on-write clone, not a symlink, so npm is safe here: an
npm install or npm ci in this worktree cannot reach the primary checkout.

KNOWN LIMITATION — read before trusting a full-suite run.
This clone is host-only. It is not a replacement for the container-local
Composer install required by ./capell, and a dependency manifest change makes
this script refuse to clone until the worktree has its own real install.

Use this setup for fast, targeted runs, and confirm the classes you care about
resolve here before believing a result:

  php -r 'require "vendor/autoload.php";
    echo (new ReflectionClass("Your\\Changed\\Class"))->getFileName(), PHP_EOL;'

For an authoritative container or full-suite run, do a real composer install
in this worktree through ./capell.
EOF
