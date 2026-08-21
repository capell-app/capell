#!/usr/bin/env bash

set -euo pipefail

repository_root="${CAPELL_CHECKOUT:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
fixture_root="${repository_root}/tests/fixtures/container-quickstart"
smoke_parent="${CAPELL_CONTAINER_SMOKE_ROOT:-}"
remove_smoke_parent=false

if [[ -z "${smoke_parent}" ]]; then
    smoke_parent="$(mktemp -d "${TMPDIR:-/tmp}/capell-container-quickstart.XXXXXX")"
    remove_smoke_parent=true
else
    mkdir -p "${smoke_parent}"
fi

consumer_root="${smoke_parent}/consumer"
project_name="capell-doc-smoke-$RANDOM-$$"
project_name="${project_name//_/-}"
compose=(docker compose --project-name "${project_name}" -f compose.sqlite.yaml)
production_project_name="${project_name}-production"
production_compose=(docker compose --project-name "${production_project_name}" --env-file .env.production -f compose.production.yaml)
keep_smoke="${CAPELL_CONTAINER_SMOKE_KEEP:-false}"
development_image="${project_name}-app:latest"
production_image="${project_name}-production:latest"
web_image="${project_name}-web:latest"

cleanup() {
    local status=$?

    if [[ "${keep_smoke}" == "true" ]]; then
        printf 'Container smoke retained at %s with Compose project %s.\n' "${consumer_root}" "${project_name}"

        return "${status}"
    fi

    if [[ -f "${consumer_root}/compose.sqlite.yaml" ]]; then
        if [[ "${status}" -ne 0 ]]; then
            "${compose[@]}" --project-directory "${consumer_root}" logs --no-color --tail=200 app || true
        fi

        "${compose[@]}" --project-directory "${consumer_root}" down --volumes --remove-orphans >/dev/null 2>&1 || true
    fi

    if [[ -f "${consumer_root}/compose.production.yaml" ]]; then
        if [[ "${status}" -ne 0 ]]; then
            "${production_compose[@]}" --project-directory "${consumer_root}" logs --no-color --tail=200 app web || true
        fi

        "${production_compose[@]}" --project-directory "${consumer_root}" down --volumes --remove-orphans >/dev/null 2>&1 || true
    fi

    docker image rm "${web_image}" "${production_image}" "${development_image}" >/dev/null 2>&1 || true

    if [[ "${remove_smoke_parent}" == "true" ]]; then
        rm -rf "${smoke_parent}"
    fi

    return "${status}"
}

trap cleanup EXIT

for binary in composer curl docker php; do
    if ! command -v "${binary}" >/dev/null 2>&1; then
        printf 'Missing required command: %s\n' "${binary}" >&2
        exit 1
    fi
done

docker info >/dev/null

if [[ -e "${consumer_root}" ]]; then
    printf 'Consumer target already exists: %s\n' "${consumer_root}" >&2
    exit 1
fi

composer create-project \
    --no-interaction \
    --no-progress \
    --prefer-dist \
    laravel/laravel \
    "${consumer_root}"

mkdir -p "${consumer_root}/.docker" "${consumer_root}/packages/capell"
cp "${fixture_root}/.dockerignore" "${consumer_root}/.dockerignore"
cp "${fixture_root}/Dockerfile" "${consumer_root}/.docker/Dockerfile"
cp "${fixture_root}/nginx.conf" "${consumer_root}/.docker/nginx.conf"
cp "${fixture_root}/compose.sqlite.yaml" "${consumer_root}/compose.sqlite.yaml"
cp "${fixture_root}/compose.production.yaml" "${consumer_root}/compose.production.yaml"
cp "${fixture_root}/.env.production.example" "${consumer_root}/.env.production"

production_app_key="$(php -r 'echo "base64:" . base64_encode(random_bytes(32));')"
CAPELL_PRODUCTION_APP_KEY="${production_app_key}" php -r '
$path = $argv[1];
$content = file_get_contents($path);

if (! is_string($content)) {
    throw new RuntimeException("Unable to read the production environment fixture.");
}

$updated = preg_replace(
    "/^APP_KEY=.*$/m",
    "APP_KEY=" . getenv("CAPELL_PRODUCTION_APP_KEY"),
    $content,
    1,
    $replacements,
);

if (! is_string($updated) || $replacements !== 1 || file_put_contents($path, $updated) === false) {
    throw new RuntimeException("Unable to generate the production application key.");
}
' "${consumer_root}/.env.production"

for package in admin core frontend installer marketplace; do
    cp -R "${repository_root}/packages/${package}" "${consumer_root}/packages/capell/${package}"
done

touch "${consumer_root}/database/database.sqlite"

cd "${consumer_root}"

docker compose --env-file .env.production -f compose.production.yaml config --quiet
"${compose[@]}" config --quiet
"${compose[@]}" build app

"${compose[@]}" run --rm app sh -lc '
    set -eu
    composer config minimum-stability dev
    composer config prefer-stable true
    composer config repositories.capell-core --json '\''{"type":"path","url":"packages/capell/core","options":{"symlink":false,"versions":{"capell-app/core":"1.x-dev"}}}'\''
    composer config repositories.capell-admin --json '\''{"type":"path","url":"packages/capell/admin","options":{"symlink":false,"versions":{"capell-app/admin":"1.x-dev"}}}'\''
    composer config repositories.capell-frontend --json '\''{"type":"path","url":"packages/capell/frontend","options":{"symlink":false,"versions":{"capell-app/frontend":"1.x-dev"}}}'\''
    composer config repositories.capell-installer --json '\''{"type":"path","url":"packages/capell/installer","options":{"symlink":false,"versions":{"capell-app/installer":"1.x-dev"}}}'\''
    composer config repositories.capell-marketplace --json '\''{"type":"path","url":"packages/capell/marketplace","options":{"symlink":false,"versions":{"capell-app/marketplace":"1.x-dev"}}}'\''
    composer require --no-interaction --no-progress --with-all-dependencies capell-app/installer:@dev
'

"${compose[@]}" run --rm app npm install --package-lock-only --no-audit --no-fund

"${compose[@]}" run --rm app php artisan capell:install \
    --fresh=force \
    --demo \
    --package-mode=all \
    --theme=default \
    --seed \
    --url=http://localhost:8000 \
    --name="Capell owner" \
    --email=owner@example.test \
    --password=replace-this-local-password \
    --clear-cache \
    --install-welcome-route \
    --no-interaction

docker build \
    --quiet \
    --file .docker/Dockerfile \
    --target production \
    --tag "${production_image}" \
    .
docker build \
    --quiet \
    --file .docker/Dockerfile \
    --target web \
    --tag "${web_image}" \
    .
docker run --rm "${production_image}" sh -lc 'test ! -e .env && test ! -e .env.production'
docker run --rm "${production_image}" test -e bootstrap/cache/capell-package-manifests.php

reserve_port() {
    php -r '
$socket = stream_socket_server("tcp://127.0.0.1:0", $errorCode, $errorMessage);

if ($socket === false) {
    throw new RuntimeException($errorMessage, $errorCode);
}

$address = stream_socket_get_name($socket, false);
fclose($socket);

if (! is_string($address) || ! str_contains($address, ":")) {
    throw new RuntimeException("Unable to reserve a smoke port.");
}

echo substr($address, strrpos($address, ":") + 1);
'
}

export CAPELL_APP_IMAGE="${production_image}"
export CAPELL_WEB_IMAGE="${web_image}"
production_port="$(reserve_port)"
export CAPELL_HTTP_PORT="${production_port}"
"${production_compose[@]}" config --quiet
"${production_compose[@]}" up -d db

database_ready=false

for attempt in {1..60}; do
    database_container="$("${production_compose[@]}" ps -q db)"

    if [[ -n "${database_container}" ]] && [[ "$(docker inspect --format '{{.State.Health.Status}}' "${database_container}")" == "healthy" ]]; then
        database_ready=true
        break
    fi

    sleep 1
done

if [[ "${database_ready}" != "true" ]]; then
    printf 'Production database did not become healthy.\n' >&2
    exit 1
fi

"${production_compose[@]}" run --rm app php artisan migrate --force
"${production_compose[@]}" run --rm --user root app php artisan capell:install \
    --production \
    --package-mode=all \
    --theme=default \
    --url="http://127.0.0.1:${production_port}" \
    --name="Capell owner" \
    --email=owner@example.test \
    --password=replace-this-production-password \
    --clear-cache \
    --install-welcome-route \
    --no-interaction
"${production_compose[@]}" run --rm app php artisan capell:doctor
"${production_compose[@]}" up -d app web

production_response_path="${smoke_parent}/response-production.html"
production_ready=false

for attempt in {1..60}; do
    if curl --max-time 2 --location --fail --silent --show-error "http://127.0.0.1:${production_port}/" --output "${production_response_path}"; then
        production_ready=true
        break
    fi

    sleep 1
done

if [[ "${production_ready}" != "true" ]] || [[ ! -s "${production_response_path}" ]]; then
    printf 'Production PHP-FPM/Nginx pair did not serve the home page.\n' >&2
    exit 1
fi

"${production_compose[@]}" down --volumes --remove-orphans >/dev/null

smoke_port="$(reserve_port)"
export CAPELL_HTTP_PORT="${smoke_port}"
http_timeout_seconds="${CAPELL_CONTAINER_SMOKE_HTTP_TIMEOUT_SECONDS:-60}"

"${compose[@]}" up -d app

ready=false

for attempt in {1..30}; do
    if "${compose[@]}" exec -T app php -r '
        $socket = @fsockopen("127.0.0.1", 8000, $errorCode, $errorMessage, 1);

        if ($socket === false) {
            exit(1);
        }

        fclose($socket);
    '; then
        ready=true
        break
    fi

    sleep 1
done

if [[ "${ready}" != "true" ]]; then
    printf 'Container did not become ready on port %s.\n' "${smoke_port}" >&2
    exit 1
fi

root_response_path="${smoke_parent}/response-root.html"
curl \
    --max-time "${http_timeout_seconds}" \
    --location \
    --fail \
    --silent \
    --show-error \
    "http://127.0.0.1:${smoke_port}/" \
    --output "${root_response_path}"
test -s "${root_response_path}"

"${compose[@]}" exec -T app php artisan capell:doctor

admin_response_path="${smoke_parent}/response-admin-login.html"
curl \
    --max-time "${http_timeout_seconds}" \
    --location \
    --fail \
    --silent \
    --show-error \
    "http://127.0.0.1:${smoke_port}/admin/login" \
    --output "${admin_response_path}"
test -s "${admin_response_path}"

printf 'Container quickstart smoke passed: capell:doctor, /, and /admin/login.\n'
