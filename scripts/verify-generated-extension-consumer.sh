#!/usr/bin/env bash

# Generates minimal and full extensions with capell:make-extension, runs each
# generated suite as a standalone package, then installs the full extension into
# a fresh Laravel host by following its generated README. The host checks
# settings save/reload through Extensions management and the widget's editor,
# asset and public behaviour while enabled, disabled and re-enabled.
#
# The fixtures in scripts/generated-extension-consumer are host-application code
# and use Layout Builder and the host's own TestCase, so they are kept as stubs
# and copied into the consumer rather than analysed with this repository.
#
# Requires network access for Composer and a Capell Packages checkout that
# provides Layout Builder and the default theme:
#   CAPELL_PACKAGES_CHECKOUT=/path/to/capell-packages bash scripts/verify-generated-extension-consumer.sh

set -euo pipefail

repository_root="${CAPELL_CHECKOUT:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
packages_root="${CAPELL_PACKAGES_CHECKOUT:?Set CAPELL_PACKAGES_CHECKOUT to a Capell Packages checkout}"
consumer_root="${CAPELL_GENERATED_CONSUMER_ROOT:-}"
fixtures="${repository_root}/scripts/generated-extension-consumer"
remove_consumer=false

if [[ -z "${consumer_root}" ]]; then
    consumer_root="$(mktemp -d "${TMPDIR:-/tmp}/capell-generated-consumer.XXXXXX")"
    remove_consumer=true
fi

cleanup() {
    if [[ "${remove_consumer}" == true ]]; then
        rm -rf "${consumer_root}"
    fi
}

trap cleanup EXIT

full_package="acme/review-tools"
full_slug="review-tools"
full_namespace='Acme\ReviewTools'
full_widget_key="acme.review-tools"
minimal_package="acme/minimal-tools"
minimal_slug="minimal-tools"
admin_email="admin@example.test"

# Path repositories model local development against this checkout. Explicit
# 1.x-dev versions let detached merge refs satisfy the ^1.0 constraints.
add_capell_repositories() {
    local core_package packages_package

    composer config minimum-stability dev
    composer config prefer-stable true

    for core_package in core admin frontend installer marketplace; do
        composer config "repositories.capell-${core_package}" --json \
            "{\"type\":\"path\",\"url\":\"${repository_root}/packages/${core_package}\",\"options\":{\"symlink\":false,\"versions\":{\"capell-app/${core_package}\":\"1.x-dev\"}}}"
    done

    for packages_package in layout-builder block-library navigation theme-foundation; do
        composer config "repositories.capell-${packages_package}" --json \
            "{\"type\":\"path\",\"url\":\"${packages_root}/packages/${packages_package}\",\"options\":{\"symlink\":false,\"versions\":{\"capell-app/${packages_package}\":\"1.x-dev\"}}}"
    done
}

mkdir -p "${consumer_root}/generated" "${consumer_root}/standalone"

(
    cd "${repository_root}"
    php vendor/bin/testbench capell:make-extension "${full_package}" \
        --name="Review Tools" --profile=full --path="${consumer_root}/generated" --no-interaction
    php vendor/bin/testbench capell:make-extension "${minimal_package}" \
        --name="Minimal Tools" --profile=minimal --path="${consumer_root}/generated" --no-interaction
)

# Each generated README tells authors to run `composer install` then `composer test`.
for slug in "${minimal_slug}" "${full_slug}"; do
    cp -R "${consumer_root}/generated/${slug}" "${consumer_root}/standalone/${slug}"
    (
        cd "${consumer_root}/standalone/${slug}"
        add_capell_repositories
        composer config allow-plugins.pestphp/pest-plugin true
        composer install --no-interaction --no-progress
        composer test -- --fail-on-risky
    )
done

composer create-project --no-interaction --no-progress --prefer-dist laravel/laravel "${consumer_root}/host"
cd "${consumer_root}/host"
add_capell_repositories
composer config "repositories.generated-${full_slug}" --json \
    "{\"type\":\"path\",\"url\":\"${consumer_root}/generated/${full_slug}\",\"options\":{\"symlink\":false,\"versions\":{\"${full_package}\":\"1.0.0\"}}}"

composer require --no-interaction --no-progress --with-all-dependencies capell-app/installer:@dev
admin_password="$(php -r 'echo bin2hex(random_bytes(16));')"
php artisan capell:install --no-interaction --url=http://generated-consumer.test \
    --name=Admin --email="${admin_email}" --password="${admin_password}" --clear-cache

# The generated README's host sequence.
composer require --no-interaction --no-progress --with-all-dependencies "${full_package}:*"
composer update "${full_package}" --with-dependencies --no-interaction --no-progress
php artisan capell:package-cache:clear
php artisan config:clear
php artisan capell:extension-install "${full_package}" --dry-run
php artisan capell:extension-install "${full_package}"
php artisan vendor:publish --tag="${full_slug}-assets"
php artisan capell:frontend-tailwind-assets
# Both commands exit zero on warnings, so require their clean-result messages.
require_clean_contract() {
    local contract_output

    contract_output="$(php artisan "$1" "${consumer_root}/generated/${full_slug}")"
    printf '%s\n' "${contract_output}"
    if [[ "${contract_output}" != *"$2"* ]]; then
        printf '%s reported extension contract findings.\n' "$1" >&2
        exit 1
    fi
}

require_clean_contract capell:package:lint "Package lint passed."
require_clean_contract capell:extension-audit "No extension contract errors found."

cp "${fixtures}/GeneratedExtensionSettingsTest.php.stub" tests/Feature/GeneratedExtensionSettingsTest.php
cp "${fixtures}/GeneratedExtensionLifecycleTest.php.stub" tests/Feature/GeneratedExtensionLifecycleTest.php
export CAPELL_GENERATED_PACKAGE="${full_package}"
export CAPELL_GENERATED_NAMESPACE="${full_namespace}"
export CAPELL_GENERATED_WIDGET_KEY="${full_widget_key}"
export CAPELL_GENERATED_SETTINGS_CLASS="${full_namespace}\\Settings\\PackageSettings"
export CAPELL_GENERATED_ADMIN_EMAIL="${admin_email}"

# Each step is a separate PHPUnit process so the host boots afresh after a lifecycle change.
lifecycle() {
    CAPELL_GENERATED_LIFECYCLE_STEP="$1" DB_DATABASE="${consumer_root}/host/database/database.sqlite" \
        php vendor/bin/phpunit --do-not-cache-result --configuration=phpunit.xml \
            tests/Feature/GeneratedExtensionLifecycleTest.php
}

lifecycle enabled

cp database/database.sqlite "${consumer_root}/settings-test.sqlite"
DB_DATABASE="${consumer_root}/settings-test.sqlite" \
    php vendor/bin/phpunit --do-not-cache-result --configuration=phpunit.xml \
        tests/Feature/GeneratedExtensionSettingsTest.php

lifecycle disable
lifecycle disabled
lifecycle enable
lifecycle enabled

printf 'Generated extension consumer verified at %s\n' "${consumer_root}"
