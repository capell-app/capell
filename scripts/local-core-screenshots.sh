#!/usr/bin/env bash

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_FILE="${ROOT}/.env.deploy.local"
RUNNER_PATH="${CAPELL_SCREENSHOT_RUNNER_PATH:-/Users/ben/Sites/packages/capell/capell-screenshot-runner}"
DRY_RUN=false
SKIP_BUILD=false
ONLY_ARGS=()

usage() {
  cat <<'USAGE'
Usage: scripts/local-core-screenshots.sh [options]

Options:
  --package <name>        Capture or validate one package. Repeatable.
  --only-file <path>      File containing package names to capture.
  --runner-path <path>    capell-screenshot-runner checkout.
  --env-file <path>       Env file. Defaults to .env.deploy.local.
  --dry-run               Validate manifests without browser capture.
  --skip-build            Pass --skip-build to the screenshot runner.
  -h, --help              Show this help.
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
    --runner-path)
      RUNNER_PATH="${2:-}"
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
    --skip-build)
      SKIP_BUILD=true
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

if [[ ! -f "${RUNNER_PATH}/src/cli.mjs" ]]; then
  echo "Screenshot runner was not found at ${RUNNER_PATH}." >&2
  exit 1
fi

export CAPELL_SCREENSHOT_RUNNER_PATH="${RUNNER_PATH}"
export CAPELL_CORE_REPO_PATH="${ROOT}"
export CAPELL_REPO="${ROOT}"
export CAPELL_SCREENSHOT_APP_PATH="${RUNNER_PATH}"
export CAPELL_ADMIN_URL="${CAPELL_ADMIN_URL:-http://127.0.0.1:8000/admin}"
export CAPELL_FRONTEND_URL="${CAPELL_FRONTEND_URL:-http://127.0.0.1:8000}"
export DB_CONNECTION="${DB_CONNECTION:-sqlite}"
export DB_DATABASE="${DB_DATABASE:-${RUNNER_PATH}/database/database.sqlite}"
export CAPELL_SCREENSHOT_SKIP_COMPOSER_UPDATE="${CAPELL_SCREENSHOT_SKIP_COMPOSER_UPDATE:-false}"

npm ci --prefix "${RUNNER_PATH}"

if [[ "${DRY_RUN}" == true ]]; then
  npm run screenshots:check -- --runner "${RUNNER_PATH}" --repo "${ROOT}" --dry-run --skip-build "${ONLY_ARGS[@]}"
  exit $?
fi

npm run install:browsers --prefix "${RUNNER_PATH}"
npm run prepare:app --prefix "${RUNNER_PATH}"

SCREENSHOT_ARGS=(--runner "${RUNNER_PATH}" --repo "${ROOT}" "${ONLY_ARGS[@]}")

if [[ "${SKIP_BUILD}" == true ]]; then
  SCREENSHOT_ARGS+=(--skip-build)
fi

npm run screenshots -- "${SCREENSHOT_ARGS[@]}"
