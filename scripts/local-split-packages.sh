#!/usr/bin/env bash

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_FILE="${ROOT}/.env.deploy.local"
DRY_RUN=false
FORCE_ALIGN=false
REF=""
TAG=""
BRANCH="${CAPELL_SPLIT_BRANCH:-main}"
ORG="${CAPELL_SPLIT_ORG:-capell-app}"
REMOTE_TEMPLATE="${CAPELL_SPLIT_REMOTE_TEMPLATE:-}"
SELECTED_PACKAGES=()
PUSHED_PACKAGES=()

usage() {
  cat <<'USAGE'
Usage: scripts/local-split-packages.sh --tag <tag> [options]

Options:
  --tag <tag>               Release tag to split and push.
  --ref <branch|tag|sha>    Source ref to split. Defaults to --tag.
  --package <name>          Package to split. Repeatable. Defaults to all public split packages.
  --branch <branch>         Destination branch. Defaults to main.
  --org <org>               GitHub org. Defaults to capell-app.
  --remote-template <fmt>   printf template for repo URL, e.g. file:///tmp/%s.git.
  --env-file <path>         Env file. Defaults to .env.deploy.local.
  --dry-run                 Print commands without pushing.
  --force-align             Snapshot the destination branch, then align it with --force-with-lease.
  -h, --help                Show this help.
USAGE
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --tag)
      TAG="${2:-}"
      shift 2
      ;;
    --ref)
      REF="${2:-}"
      shift 2
      ;;
    --package)
      SELECTED_PACKAGES+=("${2:-}")
      shift 2
      ;;
    --branch)
      BRANCH="${2:-}"
      shift 2
      ;;
    --org)
      ORG="${2:-}"
      shift 2
      ;;
    --remote-template)
      REMOTE_TEMPLATE="${2:-}"
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
    --force-align)
      FORCE_ALIGN=true
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

TAG="${TAG:-}"
REF="${REF:-${TAG}}"

if [[ -z "${TAG}" ]]; then
  echo "Missing required --tag value." >&2
  exit 1
fi

if [[ -z "${REF}" ]]; then
  echo "Missing source ref. Pass --ref or --tag." >&2
  exit 1
fi

MATRIX_PACKAGES=()
while IFS= read -r package; do
  MATRIX_PACKAGES+=("${package}")
done < <(php -r '
  $packages = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
  foreach ($packages as $package) {
      echo $package, PHP_EOL;
  }
' "${ROOT}/config/release-packages.json")

PACKAGES=("${MATRIX_PACKAGES[@]}")

if [[ ${#SELECTED_PACKAGES[@]} -gt 0 ]]; then
  PACKAGES=("${SELECTED_PACKAGES[@]}")
fi

require_package_in_matrix() {
  local package="$1"
  local known

  for known in "${MATRIX_PACKAGES[@]}"; do
    if [[ "${known}" == "${package}" ]]; then
      return 0
    fi
  done

  echo "Package '${package}' is not a public split package." >&2
  exit 1
}

github_token() {
  if [[ -n "${CAPELL_GITHUB_TOKEN:-}" ]]; then
    printf '%s' "${CAPELL_GITHUB_TOKEN}"
    return
  fi

  if command -v gh >/dev/null 2>&1; then
    gh auth token 2>/dev/null
    return
  fi

  return 1
}

remote_url_for() {
  local repository="$1"
  local token

  if [[ -n "${REMOTE_TEMPLATE}" ]]; then
    printf "${REMOTE_TEMPLATE}" "${repository}"
    return
  fi

  token="$(github_token || true)"

  if [[ -z "${token}" ]]; then
    echo "CAPELL_GITHUB_TOKEN is required, or install/authenticate gh." >&2
    exit 1
  fi

  printf 'https://x-access-token:%s@github.com/%s/%s.git' "${token}" "${ORG}" "${repository}"
}

run() {
  if [[ "${DRY_RUN}" == true ]]; then
    printf '[dry-run] %q' "$1"
    shift
    printf ' %q' "$@"
    printf '\n'
    return
  fi

  "$@"
}

cd "${ROOT}"

rollback_release_tags() {
  local package
  local remote_url

  if [[ ${#PUSHED_PACKAGES[@]} -eq 0 ]]; then
    return
  fi

  echo "Split failed; removing ${TAG} from repositories updated by this run." >&2

  for package in "${PUSHED_PACKAGES[@]}"; do
    remote_url="$(remote_url_for "${package}")"
    git push "${remote_url}" ":refs/tags/${TAG}" || true
  done
}

trap rollback_release_tags ERR

for package in "${PACKAGES[@]}"; do
  require_package_in_matrix "${package}"

  if [[ ! -d "packages/${package}" ]]; then
    echo "Missing package directory: packages/${package}" >&2
    exit 1
  fi

  echo "Splitting packages/${package} from ${REF} for ${ORG}/${package}:${BRANCH} (${TAG})."

  if [[ "${DRY_RUN}" == true ]]; then
    echo "[dry-run] git subtree split --prefix packages/${package} ${REF}"
    echo "[dry-run] git push <${ORG}/${package}> <split-sha>:refs/heads/${BRANCH}"
    echo "[dry-run] git push <${ORG}/${package}> <split-sha>:refs/tags/${TAG}"
    continue
  fi

  split_sha="$(git subtree split --prefix "packages/${package}" "${REF}")"
  remote_url="$(remote_url_for "${package}")"

  if [[ "${FORCE_ALIGN}" == true ]]; then
    remote_branch_sha="$(git ls-remote "${remote_url}" "refs/heads/${BRANCH}" | awk 'NR == 1 { print $1 }')"

    if [[ -n "${remote_branch_sha}" ]]; then
      backup_ref="refs/remotes/capell-split/${package}/${BRANCH}"
      git fetch --no-tags "${remote_url}" "+refs/heads/${BRANCH}:${backup_ref}"
      git push "${remote_url}" "${backup_ref}:refs/heads/release-backup/${TAG}-${BRANCH}"
      git push "--force-with-lease=refs/heads/${BRANCH}:${remote_branch_sha}" "${remote_url}" "${split_sha}:refs/heads/${BRANCH}"
    else
      git push "${remote_url}" "${split_sha}:refs/heads/${BRANCH}"
    fi
  else
    git push "${remote_url}" "${split_sha}:refs/heads/${BRANCH}"
  fi
  git push "${remote_url}" "${split_sha}:refs/tags/${TAG}"
  PUSHED_PACKAGES+=("${package}")
done

trap - ERR
