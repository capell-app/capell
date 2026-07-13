#!/usr/bin/env bash

set -euo pipefail

REPOSITORY_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_FILE="${REPOSITORY_ROOT}/.env.deploy.local"
DRY_RUN=false
REUSE_APP=false
ONLY_ARGS=()

usage() {
    cat <<'USAGE'
Usage: scripts/local-core-screenshots.sh [options]

Options:
  --package <name>     Capture or validate one package. Repeatable.
  --only-file <path>   File containing package or package:entry filters.
  --env-file <path>    Env file. Defaults to .env.deploy.local.
  --dry-run            Validate manifests without preparing or capturing.
  --reuse-app          Reuse an already-running Testbench workbench.
  -h, --help           Show this help.
USAGE
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --package|--only)
            ONLY_ARGS+=(--only "${2:-}")
            shift 2
            ;;
        --only-file)
            ONLY_ARGS+=(--only-file "${2:-}")
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
npm ci

if [[ "${DRY_RUN}" == true ]]; then
    npm run screenshots:check -- "${ONLY_ARGS[@]}"
    exit
fi

npx capell-screenshots install-browser

if [[ "${REUSE_APP}" == false ]]; then
    bash scripts/screenshots/prepare-workbench.sh
fi

SCREENSHOT_ARGS=("${ONLY_ARGS[@]}")
if [[ "${REUSE_APP}" == true ]]; then
    SCREENSHOT_ARGS+=(--reuse-app)
fi

npm run screenshots -- "${SCREENSHOT_ARGS[@]}"
