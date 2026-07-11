#!/usr/bin/env bash

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PACKAGIST_USERNAME="${PACKAGIST_USERNAME:-}"
PACKAGIST_API_TOKEN="${PACKAGIST_API_TOKEN:-}"
ORG="${CAPELL_SPLIT_ORG:-capell-app}"
INCLUDE_ROOT=false
DRY_RUN=false
SETUP_GITHUB_HOOKS=false
SELECTED_PACKAGES=()

usage() {
  cat <<'USAGE'
Usage: scripts/create-packagist-packages.sh [options]

Creates the Capell split packages on packagist.org from their GitHub repositories.

Environment:
  PACKAGIST_USERNAME       Packagist account username.
  PACKAGIST_API_TOKEN      Main Packagist API token.
  CAPELL_SPLIT_ORG         GitHub org. Defaults to capell-app.

Options:
  --package <name>         Package/repository slug to create. Repeatable.
                           Defaults to the split workflow matrix.
  --include-root           Also create capell-app/capell from the monorepo root.
  --setup-github-hooks     Add Packagist push webhooks to the GitHub repositories.
  --dry-run                Print the packages that would be created.
  -h, --help               Show this help.
USAGE
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --package)
      SELECTED_PACKAGES+=("${2:-}")
      shift 2
      ;;
    --include-root)
      INCLUDE_ROOT=true
      shift
      ;;
    --setup-github-hooks)
      SETUP_GITHUB_HOOKS=true
      shift
      ;;
    --dry-run)
      DRY_RUN=true
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

MATRIX_PACKAGES=()
while IFS= read -r package; do
  MATRIX_PACKAGES+=("${package}")
done < <(
  awk '/matrix:/{in_matrix=1} in_matrix && /^[[:space:]]+- [a-z0-9-]+[[:space:]]*$/ {gsub(/^[[:space:]]+- /, ""); gsub(/[[:space:]]+$/, ""); print}' "${ROOT}/.github/workflows/split-monorepo.yml"
)

if [[ ${#MATRIX_PACKAGES[@]} -eq 0 ]]; then
  echo "Could not read package matrix from .github/workflows/split-monorepo.yml." >&2
  exit 1
fi

PACKAGES=("${MATRIX_PACKAGES[@]}")

if [[ ${#SELECTED_PACKAGES[@]} -gt 0 ]]; then
  PACKAGES=("${SELECTED_PACKAGES[@]}")
fi

if [[ "${INCLUDE_ROOT}" == true ]]; then
  PACKAGES+=("capell")
fi

if [[ "${DRY_RUN}" != true && ( -z "${PACKAGIST_USERNAME}" || -z "${PACKAGIST_API_TOKEN}" ) ]]; then
  echo "PACKAGIST_USERNAME and PACKAGIST_API_TOKEN are required." >&2
  exit 1
fi

package_exists() {
  local package="$1"
  local status

  status="$(curl -sS -o /dev/null -w '%{http_code}' "https://repo.packagist.org/p2/${ORG}/${package}.json")"
  [[ "${status}" == "200" ]]
}

create_package() {
  local package="$1"
  local repository="https://github.com/${ORG}/${package}"

  if package_exists "${package}"; then
    echo "Already exists: ${ORG}/${package}"
    return
  fi

  if [[ "${DRY_RUN}" == true ]]; then
    echo "[dry-run] Create ${ORG}/${package} from ${repository}"
    return
  fi

  echo "Creating ${ORG}/${package} from ${repository}"

  curl -fsS \
    -X POST \
    -H 'Content-Type: application/json' \
    -H "Authorization: Bearer ${PACKAGIST_USERNAME}:${PACKAGIST_API_TOKEN}" \
    'https://packagist.org/api/create-package' \
    -d "{\"repository\":\"${repository}\"}"

  echo
}

hook_exists() {
  local package="$1"
  local payload_url="https://packagist.org/api/github?username=${PACKAGIST_USERNAME}"

  gh api "repos/${ORG}/${package}/hooks" \
    --paginate \
    --jq ".[] | select(.config.url == \"${payload_url}\") | .id" \
    | grep -q .
}

create_github_hook() {
  local package="$1"
  local payload_url="https://packagist.org/api/github?username=${PACKAGIST_USERNAME}"

  if [[ "${SETUP_GITHUB_HOOKS}" != true ]]; then
    return
  fi

  if [[ "${DRY_RUN}" == true ]]; then
    echo "[dry-run] Create GitHub Packagist webhook for ${ORG}/${package}"
    return
  fi

  if hook_exists "${package}"; then
    echo "Hook already exists: ${ORG}/${package}"
    return
  fi

  echo "Creating GitHub Packagist webhook for ${ORG}/${package}"

  gh api "repos/${ORG}/${package}/hooks" \
    --method POST \
    --field name=web \
    --field active=true \
    --field events[]=push \
    --field config[url]="${payload_url}" \
    --field config[content_type]=json \
    --field config[secret]="${PACKAGIST_API_TOKEN}"
}

for package in "${PACKAGES[@]}"; do
  create_package "${package}"
  create_github_hook "${package}"
done
