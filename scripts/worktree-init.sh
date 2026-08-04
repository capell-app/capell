#!/usr/bin/env bash
################################################################################
#
# Provision a checkout so every local quality gate can actually run.
#
# `git worktree add` materialises tracked files only. `vendor/`, `node_modules/`,
# `.env` and the `.deploy-packages` symlink are all gitignored, so a fresh
# worktree starts without the toolchain that Pint, Prettier, PHPStan and the
# Composer path repositories need. This script closes that gap, and arms the
# pre-commit hooks that report on it.
#
# Idempotent: safe to re-run, does nothing when the checkout is already whole.
#
# Usage
#   scripts/worktree-init.sh              provision this checkout
#   scripts/worktree-init.sh --check      report readiness only, change nothing
#   scripts/worktree-init.sh --install    always run composer install / npm ci
#                                         instead of cloning from the primary
#
# Exit 0 when the checkout is ready, 1 when it is not (or --check found a gap).
#
################################################################################

set -euo pipefail

mode="provision"

while [ "$#" -gt 0 ]; do
    case "$1" in
        --check) mode="check" ;;
        --install) mode="install" ;;
        -h|--help) sed -n '3,20p' "$0" | sed 's|^# \{0,1\}||'; exit 0 ;;
        *) printf 'Unknown argument: %s\n' "$1" >&2; exit 2 ;;
    esac
    shift
done

repo_root="$(git rev-parse --show-toplevel)"
cd "$repo_root"

git_common_dir="$(git rev-parse --git-common-dir)"
case "$git_common_dir" in
    /*) ;;
    *) git_common_dir="$(cd "$git_common_dir" && pwd)" ;;
esac

# The primary checkout owns the shared administrative directory; every linked
# worktree points its `.git` file at that same path.
primary_root="$(dirname "$git_common_dir")"
siblings_root="$(dirname "$primary_root")"

if [ "$primary_root" = "$repo_root" ]; then
    kind="primary checkout"
else
    kind="linked worktree of $primary_root"
fi

gaps=0

step() { printf '  %s\n' "$*"; }
ok()   { printf '  \033[0;32mok\033[0m    %s\n' "$*"; }
fix()  { printf '  \033[0;33mfix\033[0m   %s\n' "$*"; }
gap()  { printf '  \033[0;31mgap\033[0m   %s\n' "$*"; gaps=$((gaps + 1)); }

printf '\nworktree-init: %s\n' "$repo_root"
printf '              %s\n\n' "$kind"

################################################################################
# 1. Quality gates. These are installed once per repository (worktrees share
#    $GIT_COMMON_DIR/hooks), so arming them here covers every checkout at once.
################################################################################

hooks_path="$(git config --get core.hooksPath || true)"
[ -n "$hooks_path" ] || hooks_path="$git_common_dir/hooks"

if [ -f "$hooks_path/pre-commit" ]; then
    ok "pre-commit hook armed ($hooks_path/pre-commit)"
elif [ "$mode" = "check" ]; then
    gap "pre-commit hook NOT installed — every gate in .pre-commit-config.yaml is inert"
elif command -v pre-commit >/dev/null 2>&1; then
    pre-commit install >/dev/null
    fix "installed pre-commit hook at $hooks_path/pre-commit"
else
    gap "pre-commit is not on PATH; install it (brew install pre-commit) then re-run"
fi

################################################################################
# 2. Environment and path-repository wiring. `./capell` seeds these too, but a
#    commit happens on the host without ever invoking the wrapper.
################################################################################

if [ -f .env ]; then
    ok ".env present"
elif [ ! -f "$primary_root/.env" ]; then
    : # This repository does not keep a local .env; nothing to seed.
elif [ "$mode" = "check" ]; then
    gap ".env missing"
else
    cp "$primary_root/.env" .env
    fix "seeded .env from $primary_root/.env"
fi

# `.deploy-packages` is the capell-app convention for resolving Composer path
# repositories against the sibling package tree. Only relevant where the primary
# checkout has one.
if [ ! -e "$primary_root/.deploy-packages" ]; then
    : # Not this repository's convention.
elif [ -e .deploy-packages ]; then
    ok ".deploy-packages linked"
elif [ "$mode" = "check" ]; then
    gap ".deploy-packages symlink missing — composer path repositories will not resolve"
elif [ -d "$siblings_root/packages" ]; then
    ln -s "$siblings_root/packages" .deploy-packages
    fix "linked .deploy-packages -> $siblings_root/packages"
else
    gap "no $siblings_root/packages to link .deploy-packages at"
fi

################################################################################
# 3. Dependency trees.
#
#    Cloning from the primary is near-instant and costs no extra disk on APFS
#    (`cp -c` is copy-on-write), but it is only correct when the lockfile is
#    identical — a branch that changes composer.lock or package-lock.json must
#    resolve its own tree, or the checkout runs against the wrong dependencies.
#    So: clone on a lockfile match, install otherwise. Never symlink to the
#    primary's tree — an install in one checkout would then mutate all of them.
################################################################################

provision_tree() {
    tree="$1"          # vendor | node_modules
    lockfile="$2"      # composer.lock | package-lock.json
    install_cmd="$3"   # command to resolve the tree from scratch
    probe="$4"         # a binary that must exist once the tree is good
    method=""          # how the tree was obtained, for the success line

    if [ -x "$probe" ]; then
        ok "$tree present ($probe)"
        return 0
    fi

    if [ "$mode" = "check" ]; then
        gap "$tree missing — $probe cannot run"
        return 0
    fi

    if [ "$mode" != "install" ] \
        && [ "$primary_root" != "$repo_root" ] \
        && [ -d "$primary_root/$tree" ] \
        && [ -f "$lockfile" ] \
        && [ -f "$primary_root/$lockfile" ] \
        && cmp -s "$lockfile" "$primary_root/$lockfile"; then
        # -c clones on APFS: instant, and the copy stays independent.
        cp -Rc "$primary_root/$tree" "$tree" 2>/dev/null \
            || cp -R "$primary_root/$tree" "$tree"
        method="cloned from primary ($lockfile identical)"
    else
        if [ "$mode" != "install" ] && [ -f "$lockfile" ] && [ -f "$primary_root/$lockfile" ]; then
            step "$lockfile differs from the primary checkout — resolving this branch's own tree"
        fi
        step "running: $install_cmd"
        if ! ( eval "$install_cmd" ); then
            gap "$install_cmd failed — $tree is still missing"
            return 0
        fi
        method="installed via $install_cmd"
    fi

    # Report success only once the toolchain is demonstrably usable. A tree that
    # exists but has no runnable binary is the failure this script exists to
    # prevent, so it must not be reported as a fix.
    if [ -x "$probe" ]; then
        fix "$tree $method"
        return 0
    fi

    gap "$tree $method, but $probe is still missing — gates cannot run"
}

provision_tree vendor composer.lock \
    'composer install --no-interaction --no-progress' \
    ./vendor/bin/pint

provision_tree node_modules package-lock.json \
    'npm ci --no-audit --no-fund' \
    ./node_modules/.bin/prettier

################################################################################
# 4. Report.
################################################################################

printf '\n'

if [ "$gaps" -eq 0 ]; then
    printf 'Ready. Quality gates in this checkout can run.\n\n'
    exit 0
fi

if [ "$mode" = "check" ]; then
    printf '%s gap(s). Run scripts/worktree-init.sh to provision this checkout.\n\n' "$gaps"
else
    printf '%s gap(s) remain — see above. Do not rely on local gates until they are closed.\n\n' "$gaps"
fi

exit 1
