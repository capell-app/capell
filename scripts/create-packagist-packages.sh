#!/usr/bin/env bash

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PACKAGIST_USERNAME="${PACKAGIST_USERNAME:-}"
PACKAGIST_API_TOKEN="${PACKAGIST_API_TOKEN:-}"
ORG="${CAPELL_SPLIT_ORG:-capell-app}"
DRY_RUN=false
PREFLIGHT=false
SETUP_GITHUB_HOOKS=false
SELECTED_PACKAGES=()

usage() {
  cat <<'USAGE'
Usage: scripts/create-packagist-packages.sh [options]

Creates the public Capell packages on packagist.org from their GitHub repositories.

Environment:
  PACKAGIST_USERNAME       Packagist account username.
  PACKAGIST_API_TOKEN      Main Packagist API token.
  CAPELL_SPLIT_ORG         GitHub org. Defaults to capell-app.

Options:
  --package <name>         Public package/repository slug to create. Repeatable.
                           Defaults to all packages in config/packagist-packages.json.
  --setup-github-hooks     Add Packagist push webhooks to the GitHub repositories.
  --preflight              Read-only verification of repositories, package names,
                           Packagist registrations, and GitHub webhooks.
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
    --setup-github-hooks)
      SETUP_GITHUB_HOOKS=true
      shift
      ;;
    --preflight)
      PREFLIGHT=true
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

if [[ "${PREFLIGHT}" == true && ( "${DRY_RUN}" == true || "${SETUP_GITHUB_HOOKS}" == true ) ]]; then
  echo "--preflight cannot be combined with --dry-run or --setup-github-hooks." >&2
  exit 1
fi

PUBLIC_PACKAGES=()
while IFS= read -r package; do
  PUBLIC_PACKAGES+=("${package}")
done < <(php -r '
  $catalogue = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
  foreach ($catalogue["packages"] as $package) {
      echo $package, PHP_EOL;
  }
' "${ROOT}/config/packagist-packages.json")

PACKAGES=("${PUBLIC_PACKAGES[@]}")

if [[ ${#SELECTED_PACKAGES[@]} -gt 0 ]]; then
  PACKAGES=("${SELECTED_PACKAGES[@]}")
fi

if [[ "${DRY_RUN}" != true && "${PREFLIGHT}" != true && ( -z "${PACKAGIST_USERNAME}" || -z "${PACKAGIST_API_TOKEN}" ) ]]; then
  echo "PACKAGIST_USERNAME and PACKAGIST_API_TOKEN are required." >&2
  exit 1
fi

package_exists() {
  local package="$1"
  local status

  status="$(curl -sS -o /dev/null -w '%{http_code}' "https://repo.packagist.org/p2/${ORG}/${package}.json")"
  [[ "${status}" == "200" ]]
}

preflight_package() {
  local package="$1"
  local expected_name="${ORG}/${package}"
  local repository="${ORG}/${package}"
  local composer_name
  local failed=false

  if ! gh api "repos/${repository}" --silent >/dev/null; then
    echo "[missing] GitHub repository: ${repository}" >&2
    failed=true
  else
    echo "[ok] GitHub repository: ${repository}"

    composer_name="$(
      gh api "repos/${repository}/contents/composer.json" --jq '.content' 2>/dev/null \
        | tr -d '\n' \
        | base64 --decode 2>/dev/null \
        | jq -r '.name // empty' 2>/dev/null \
        || true
    )"

    if [[ "${composer_name}" != "${expected_name}" ]]; then
      echo "[mismatch] ${repository} composer name: expected ${expected_name}, got ${composer_name:-<missing>}" >&2
      failed=true
    else
      echo "[ok] Composer package name: ${expected_name}"
    fi

    if gh api "repos/${repository}/hooks" --paginate --jq '.[] | select(.config.url | startswith("https://packagist.org/api/github")) | .id' 2>/dev/null | grep -q .; then
      echo "[ok] Packagist GitHub webhook: ${repository}"
    else
      echo "[missing] Packagist GitHub webhook: ${repository}" >&2
      failed=true
    fi
  fi

  if package_exists "${package}"; then
    echo "[ok] Packagist package: ${expected_name}"
  else
    echo "[missing] Packagist package: ${expected_name}" >&2
    failed=true
  fi

  [[ "${failed}" == false ]]
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

if [[ "${PREFLIGHT}" == true ]]; then
  PREFLIGHT_FAILED=false

  for package in "${PACKAGES[@]}"; do
    if ! preflight_package "${package}"; then
      PREFLIGHT_FAILED=true
    fi
  done

  if [[ "${PREFLIGHT_FAILED}" == true ]]; then
    echo "Packagist preflight failed." >&2
    exit 1
  fi

  echo "Packagist preflight passed."
  exit 0
fi

for package in "${PACKAGES[@]}"; do
  create_package "${package}"
  create_github_hook "${package}"
done
