#!/usr/bin/env bash

set -euo pipefail

repository_root="${CAPELL_CHECKOUT:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
consumer_root="${CAPELL_NESTED_CONSUMER_ROOT:-}"
remove_consumer=false

if [[ -z "${consumer_root}" ]]; then
    consumer_root="$(mktemp -d "${TMPDIR:-/tmp}/capell-nested-consumer.XXXXXX")"
    remove_consumer=true
fi

cleanup() {
    if [[ "${remove_consumer}" == true ]]; then
        rm -rf "${consumer_root}"
    fi
}

trap cleanup EXIT

composer create-project \
    --no-interaction \
    --no-progress \
    --prefer-dist \
    laravel/laravel \
    "${consumer_root}"

cd "${consumer_root}"

composer config minimum-stability dev
composer config prefer-stable true

# Pull-request merge refs are detached, so Composer otherwise derives dev-<sha>
# for both path packages. Explicit 1.x-dev versions model a real path consumer
# and allow the frontend package's capell-app/core:^1.0 constraint to resolve.
composer config repositories.capell-core --json \
    "{\"type\":\"path\",\"url\":\"${repository_root}/packages/core\",\"options\":{\"versions\":{\"capell-app/core\":\"1.x-dev\"}}}"
composer config repositories.capell-frontend --json \
    "{\"type\":\"path\",\"url\":\"${repository_root}/packages/frontend\",\"options\":{\"versions\":{\"capell-app/frontend\":\"1.x-dev\"}}}"
composer require \
    --no-interaction \
    --no-progress \
    --with-all-dependencies \
    capell-app/core:@dev \
    capell-app/frontend:@dev

php artisan package:discover --ansi
php artisan capell:frontend-tailwind-assets --ansi

generated_css="resources/css/capell/frontend.css"

test -s "${generated_css}"
grep --fixed-strings --quiet \
    "vendor/capell-app/frontend/resources/css/capell-frontend.css" \
    "${generated_css}"

printf '@import "./capell/frontend.css";\n' > resources/css/app.css

npm install --no-audit --no-fund
npm run build
