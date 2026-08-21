#!/usr/bin/env bash

set -euo pipefail

repository_root="${CAPELL_CHECKOUT:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
packages_root="${CAPELL_PACKAGES_ROOT:-}"
consumer_root="${CAPELL_GOLDEN_PATH_CONSUMER_ROOT:-}"
artifact_dir="${CAPELL_GOLDEN_PATH_ARTIFACT_DIR:-${repository_root}/test-results/editor-public-golden-path}"
fixture_path="${repository_root}/tests/fixtures/editor-public-golden-path.json"
server_port="${CAPELL_GOLDEN_PATH_PORT:-8765}"
base_url="http://127.0.0.1:${server_port}"
remove_consumer=false
server_pid=""
server_log=""
diagnostic_secrets='[]'
laravel_skeleton_version="${CAPELL_GOLDEN_PATH_LARAVEL_VERSION:-13.0.0}"

if [[ ! -f "${packages_root}/packages/discovery-foundation/composer.json" || ! -f "${packages_root}/packages/html-cache/composer.json" ]]; then
    echo "CAPELL_PACKAGES_ROOT must point to a capell-packages-4 checkout containing discovery-foundation and html-cache." >&2
    exit 2
fi

if [[ ! -x "${repository_root}/node_modules/.bin/playwright" ]]; then
    echo "Playwright is not installed. Run npm ci in the Capell checkout first." >&2
    exit 2
fi

if [[ ! "${laravel_skeleton_version}" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    echo "CAPELL_GOLDEN_PATH_LARAVEL_VERSION must be an exact semantic version." >&2
    exit 2
fi

if [[ "${CAPELL_GOLDEN_PATH_REQUIRE_CLEAN:-false}" == true ]] && [[ -n "$(git -C "${repository_root}" status --porcelain --untracked-files=no)" ]]; then
    echo "The exact-head journey requires a clean tracked Capell checkout." >&2
    exit 2
fi

if [[ -z "${consumer_root}" ]]; then
    consumer_root="$(mktemp -d "${TMPDIR:-/tmp}/capell-editor-public-golden-path.XXXXXX")"
    remove_consumer=true
fi

mkdir -p "${artifact_dir}"
artifact_dir="$(cd "${artifact_dir}" && pwd)"
server_log="${consumer_root}/capell-server.log"

fixture_value() {
    node -e 'const fixture = require(process.argv[1]); const value = process.argv[2].split(".").reduce((carry, key) => carry[key], fixture); process.stdout.write(String(value));' "${fixture_path}" "$1"
}

admin_name="$(fixture_value admin.name)"
admin_email="$(fixture_value admin.email)"
admin_password="$(fixture_value admin.password)"
diagnostic_secrets="$(node -e 'const fixture = require(process.argv[1]); process.stdout.write(JSON.stringify([fixture.admin.name, fixture.admin.email, fixture.admin.password]));' "${fixture_path}")"

write_failure_evidence() {
    local log_paths=("${server_log}")
    local log_path

    if [[ -d "${consumer_root}/storage/logs" ]]; then
        while IFS= read -r log_path; do
            log_paths+=("${log_path}")
        done < <(find "${consumer_root}/storage/logs" -maxdepth 1 -type f -name '*.log' -print | sort)
    fi

    CAPELL_DIAGNOSTIC_SECRETS="${diagnostic_secrets}" \
        node "${repository_root}/tests/Browser/support/redact-log.js" \
        "${artifact_dir}/backend-redacted.log" \
        "${log_paths[@]}"
}

cleanup() {
    local status=$?

    if [[ -n "${server_pid}" ]] && kill -0 "${server_pid}" 2>/dev/null; then
        kill "${server_pid}"
        if ! wait "${server_pid}" 2>/dev/null; then
            # SIGTERM is the expected shutdown path for the owned server.
            :
        fi
    fi

    if [[ "${status}" -ne 0 ]]; then
        write_failure_evidence
        echo "Golden-path failure evidence: ${artifact_dir}" >&2
    fi

    if [[ "${remove_consumer}" == true && "${CAPELL_GOLDEN_PATH_KEEP_CONSUMER:-false}" != true ]]; then
        case "${consumer_root}" in
            "${TMPDIR:-/tmp}"/capell-editor-public-golden-path.*)
                rm -rf "${consumer_root}"
                ;;
            *)
                echo "Refusing to remove unexpected consumer path: ${consumer_root}" >&2
                ;;
        esac
    fi
}

trap cleanup EXIT

root_sha="$(git -C "${repository_root}" rev-parse HEAD)"
packages_sha="${CAPELL_PACKAGES_HEAD:-}"
root_dirty=false

actual_packages_sha="$(git -C "${packages_root}" rev-parse HEAD)"

if [[ -n "${packages_sha}" && "${packages_sha}" != "${actual_packages_sha}" ]]; then
    echo "CAPELL_PACKAGES_HEAD must match the checked-out companion source." >&2
    exit 2
fi

packages_sha="${actual_packages_sha}"

if [[ ! "${packages_sha}" =~ ^[0-9a-f]{40}$ ]]; then
    echo "The companion package source revision must be a full Git SHA." >&2
    exit 2
fi

if [[ -n "$(git -C "${repository_root}" status --porcelain --untracked-files=no)" ]]; then
    root_dirty=true
fi

CAPELL_EVIDENCE_ROOT_SHA="${root_sha}" \
CAPELL_EVIDENCE_PACKAGES_SHA="${packages_sha}" \
CAPELL_EVIDENCE_ROOT_DIRTY="${root_dirty}" \
CAPELL_EVIDENCE_LARAVEL_SKELETON="${laravel_skeleton_version}" \
node -e '
const fs = require("node:fs");
const evidence = {
    capellHead: process.env.CAPELL_EVIDENCE_ROOT_SHA,
    capellPackagesHead: process.env.CAPELL_EVIDENCE_PACKAGES_SHA,
    capellTrackedCheckoutDirty: process.env.CAPELL_EVIDENCE_ROOT_DIRTY === "true",
    laravelSkeletonVersion: process.env.CAPELL_EVIDENCE_LARAVEL_SKELETON,
    generatedAt: new Date().toISOString(),
};
fs.writeFileSync(process.argv[1], `${JSON.stringify(evidence, null, 2)}\n`);
' "${artifact_dir}/source-revisions.json"

composer create-project \
    --no-interaction \
    --no-progress \
    --prefer-dist \
    laravel/laravel \
    "${consumer_root}" \
    "${laravel_skeleton_version}"

cd "${consumer_root}"

composer config minimum-stability dev
composer config prefer-stable true

path_repository_json() {
    node -e '
const packageName = process.argv[1];
const packagePath = process.argv[2];
process.stdout.write(JSON.stringify({
    type: "path",
    url: packagePath,
    options: {
        symlink: true,
        versions: { [packageName]: "1.x-dev" },
    },
}));
' "$1" "$2"
}

# Pull-request merge refs are detached. Explicit branch-alias versions make the
# fresh consumer solve the five version-aligned split packages from the checked
# out monorepo head. This matches the public release topology and preserves the
# vendor/capell-app/<package> paths used by install-time asset integration.
for foundation_package in core admin frontend installer marketplace; do
    composer config "repositories.capell-${foundation_package}" --json \
        "$(path_repository_json "capell-app/${foundation_package}" "${repository_root}/packages/${foundation_package}")"
done

composer config repositories.capell-discovery-foundation --json \
    "$(path_repository_json capell-app/discovery-foundation "${packages_root}/packages/discovery-foundation")"
composer config repositories.capell-html-cache --json \
    "$(path_repository_json capell-app/html-cache "${packages_root}/packages/html-cache")"
composer require \
    --no-interaction \
    --no-progress \
    --with-all-dependencies \
    capell-app/core:1.x-dev \
    capell-app/admin:1.x-dev \
    capell-app/frontend:1.x-dev \
    capell-app/installer:1.x-dev \
    capell-app/marketplace:1.x-dev \
    capell-app/discovery-foundation:1.x-dev \
    capell-app/html-cache:1.x-dev

php artisan --version | tee "${artifact_dir}/consumer-framework-version.txt"

cp .env.example .env
touch database/database.sqlite

cat >> .env <<ENV

APP_ENV=testing
APP_DEBUG=false
APP_URL=${base_url}
ASSET_URL=${base_url}
DB_CONNECTION=sqlite
DB_DATABASE=${consumer_root}/database/database.sqlite
CACHE_STORE=file
SESSION_DRIVER=file
QUEUE_CONNECTION=sync
MAIL_MAILER=array
CAPELL_HTML_CACHE=true
CAPELL_WRITE_HTML_CACHE=true
CAPELL_HTML_CACHE_ORIGIN_SWR=false
CAPELL_HTML_CACHE_HIT_RECORDING=false
CAPELL_HTML_CACHE_INVALIDATION_MODE=instant
ENV

php artisan key:generate --force --ansi
php artisan package:discover --ansi
php artisan migrate --force --ansi
php artisan capell:install \
    --no-interaction \
    --url="${base_url}" \
    --all-packages \
    --theme=default \
    --name="${admin_name}" \
    --email="${admin_email}" \
    --password="${admin_password}" \
    --clear-cache \
    --install-welcome-route
php artisan migrate --force --ansi
php artisan filament:assets --ansi

npm install --ignore-scripts --no-audit --no-fund

if grep --quiet 'fonts:' vite.config.js; then
    php -r '
$path = "vite.config.js";
$contents = file_get_contents($path);
$updated = preg_replace(
    "/\n\s*fonts:\s*\[\n.*?\n\s*\],/s",
    "\n            fonts: [],",
    (string) $contents,
    1,
    $replacements,
);

if ($updated === null || $replacements !== 1) {
    throw new RuntimeException("Unable to disable the consumer remote font.");
}

file_put_contents($path, $updated);
'
fi

npm run build
php artisan optimize:clear --ansi

(
    cd "${consumer_root}/public"
    exec php -S "127.0.0.1:${server_port}" \
        "${consumer_root}/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php"
) > "${server_log}" 2>&1 &
server_pid=$!

server_ready=false
for attempt in {1..30}; do
    if curl --max-time 30 --fail --silent --show-error "${base_url}/admin/login" >/dev/null; then
        server_ready=true
        break
    fi

    sleep 1
done

if [[ "${server_ready}" != true ]]; then
    echo "The fresh consumer did not become ready at ${base_url}." >&2
    exit 1
fi

CAPELL_GOLDEN_PATH_URL="${base_url}" \
CAPELL_GOLDEN_PATH_ARTIFACT_DIR="${artifact_dir}" \
    "${repository_root}/node_modules/.bin/playwright" test \
    "${repository_root}/tests/Browser/editor-public-golden-path.spec.js" \
    --config="${repository_root}/playwright.config.js" \
    --project=chromium \
    --output="${consumer_root}/playwright-output" \
    --reporter=line
