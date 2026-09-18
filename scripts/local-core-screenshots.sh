#!/usr/bin/env bash

set -euo pipefail

REPOSITORY_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_FILE="${REPOSITORY_ROOT}/.env.deploy.local"
DRY_RUN=false
REUSE_APP=false
SKIP_INSTALL=false
ONLY_ARGS=()
ONLY_ARG_COUNT=0

usage() {
    cat <<'USAGE'
Usage: scripts/local-core-screenshots.sh [options]

Options:
  --package <name>     Capture or validate one package. Repeatable.
  --only-file <path>   File containing package or package:entry filters.
  --env-file <path>    Env file. Defaults to .env.deploy.local.
  --dry-run            Validate manifests without preparing or capturing.
  --reuse-app          Reuse an already-running Testbench workbench.
  --skip-install       Use the installed node_modules instead of running npm ci.
  -h, --help           Show this help.
USAGE
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --package|--only)
            ONLY_ARGS+=(--only "${2:-}")
            ONLY_ARG_COUNT=$((ONLY_ARG_COUNT + 2))
            shift 2
            ;;
        --only-file)
            ONLY_ARGS+=(--only-file "${2:-}")
            ONLY_ARG_COUNT=$((ONLY_ARG_COUNT + 2))
            shift 2
            ;;
        --env-file)
            ENV_FILE="${2:-}"
            shift 2
            ;;
        --dry-run)
            DRY_RUN=true
            shift
            ;;
        --reuse-app)
            REUSE_APP=true
            shift
            ;;
        --skip-install)
            SKIP_INSTALL=true
            shift
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        *)
            echo "Unknown option: $1" >&2
            usage >&2
            exit 1
            ;;
    esac
done

if [[ -f "${ENV_FILE}" ]]; then
    set -a
    # shellcheck disable=SC1090
    source "${ENV_FILE}"
    set +a
fi

cd "${REPOSITORY_ROOT}"

# A sandboxed agent cannot run npm ci: it may not chmod the linked
# screenshot-tools bin outside its writable roots, and working around that
# with bin links disabled leaves node_modules without any executables.
# Skipping the install therefore requires a tree that already has them.
if [[ "${SKIP_INSTALL}" == true ]]; then
    if [[ -z "$(ls -A node_modules/.bin 2>/dev/null)" ]]; then
        echo "--skip-install needs installed dependencies, but node_modules/.bin is missing or empty. Run npm ci outside the sandbox first." >&2
        exit 1
    fi
else
    npm ci
fi

if [[ "${DRY_RUN}" == true ]]; then
    if [[ "${ONLY_ARG_COUNT}" -eq 0 ]]; then
        npm run screenshots:check
    else
        npm run screenshots:check -- "${ONLY_ARGS[@]}"
    fi

    exit
fi

# The runner has no install-browser command; Playwright installs the browser.
# Like npm ci, this is an install, so --skip-install uses what is present.
if [[ "${SKIP_INSTALL}" == false ]]; then
    npx playwright install chromium
fi

if [[ "${REUSE_APP}" == false ]]; then
    bash scripts/screenshots/prepare-workbench.sh
else
    # Reusing an app skips prepare-workbench.sh, and a composer install since the
    # last run will have re-extracted testbench and deleted its published assets.
    php vendor/bin/testbench filament:assets
fi

if [[ "${REUSE_APP}" == true ]]; then
    ONLY_ARGS+=(--reuse-app)
    ONLY_ARG_COUNT=$((ONLY_ARG_COUNT + 1))
fi

if [[ "${ONLY_ARG_COUNT}" -eq 0 ]]; then
    npm run screenshots
else
    npm run screenshots -- "${ONLY_ARGS[@]}"
fi
