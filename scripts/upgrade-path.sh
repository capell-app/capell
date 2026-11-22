#!/usr/bin/env bash
# shellcheck disable=SC2016 # the single-quoted blocks are PHP programs, not shell text
# Prove a site on the last published Capell release upgrades cleanly to this Core.
#
# Usage:
#   scripts/upgrade-path.sh [options]
#
# Options:
#   --core-path DIR     Checked-out Core monorepo to upgrade to (default: this repository).
#   --from VERSION      Published release to start from, for example 1.0.79
#                       (default: the newest stable capell-app/core on Packagist).
#   --skeleton NAME     Skeleton package for create-project (default: capell-app/capell-skeleton).
#   --port PORT         Port for `php artisan serve` on 127.0.0.1 (default: 8010).
#   --workdir DIR       Scratch directory for the app, evidence and logs (default: a new temp
#                       directory, removed after a passing run and kept after a failing one).
#   --summary FILE      Append the markdown result table here
#                       (default: $GITHUB_STEP_SUMMARY when set, otherwise only printed).
#   --inject FAULT      Seed a fault before the upgrade; the run must then fail.
#                       broken-step: an upgrade step that throws.
#                       destructive-migration: a migration that drops a populated column.
#   --no-self-check     Skip the two closing steps that seed those faults into the upgraded
#                       site to prove the check still detects them.
#   --keep              Keep the generated app after the run.
#   -h, --help          Show this help.
#
# What it does: creates a skeleton app pinned to the published release, installs Capell with
# demo content, records every table, every public page and every admin page, switches Composer
# to the Core packages in --core-path, then follows the documented deploy order (capell:upgrade,
# capell:frontend-after-install, capell:runtime-refresh). It passes only when the upgrade and
# every upgrade step succeed, a second upgrade has nothing left to do, no table, column, row or
# value has gone, every public page that rendered still renders with the title it had, every
# admin page that rendered still renders for the administrator created before the upgrade, no
# page has become a server error, and nothing was logged as an error.
#
# The check compares the upgraded site with the published one, so a fault the published release
# already has is not blamed on the upgrade: a page that was a 404 may stay one, and a sign-in
# that already failed is reported as a warning in the summary.
#
# Companion packages come from Packagist at their newest release on both sides of the upgrade,
# so this measures a Core change against what a site owner has installed today; a --from older
# than those packages accept will not resolve. The database is the skeleton's SQLite default.
# An intended removal of data is declared, with its reason, in
# scripts/upgrade-path/allowed-changes.json.
#
# Exit codes: 0 the site upgraded cleanly, 1 a step failed, 2 usage error,
# 3 a required tool is missing.
#
# Local run:  heavy run -w 2 upgrade-path -- scripts/upgrade-path.sh
# Test:       bash tests/Shell/upgrade-path.sh

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
HELPERS="$SCRIPT_DIR/upgrade-path"

CORE_PATH="$(cd "$SCRIPT_DIR/.." && pwd)"
FROM=""
SKELETON="capell-app/capell-skeleton"
PORT=8010
WORKDIR=""
SUMMARY="${GITHUB_STEP_SUMMARY:-}"
INJECT=""
SELF_CHECK=1
KEEP=0
# Higher than any released 1.x, so every ^1.0 constraint in the Packagist packages accepts it.
PATH_VERSION="1.999.0"
ADMIN_EMAIL="upgrade-path@example.test"
ERROR_LEVELS='\.(ERROR|CRITICAL|ALERT|EMERGENCY):'

STEPS=()
AUTO_WORKDIR=0
APP_DIR=""
LOG_DIR=""
EVIDENCE_DIR=""
ROWS=()
TOTAL_MS=0
FAILED_STEP=""

usage_error() {
    printf 'upgrade-path: %s\n' "$1" >&2
    printf 'Run with --help for usage.\n' >&2
    exit 2
}

print_help() {
    sed -n '2,/^$/p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
}

parse_args() {
    while (($# > 0)); do
        case "$1" in
            -h | --help)
                print_help
                exit 0
                ;;
            --keep)
                KEEP=1
                shift
                ;;
            --no-self-check)
                SELF_CHECK=0
                shift
                ;;
            --core-path | --from | --skeleton | --port | --workdir | --summary | --inject)
                (($# >= 2)) || usage_error "$1 needs a value"
                case "$1" in
                    --core-path) CORE_PATH="$2" ;;
                    --from) FROM="${2#v}" ;;
                    --skeleton) SKELETON="$2" ;;
                    --port) PORT="$2" ;;
                    --workdir) WORKDIR="$2" ;;
                    --summary) SUMMARY="$2" ;;
                    --inject) INJECT="$2" ;;
                esac
                shift 2
                ;;
            *)
                usage_error "unknown option: $1"
                ;;
        esac
    done

    [[ "$PORT" =~ ^[0-9]+$ && "$PORT" -ge 1 && "$PORT" -le 65535 ]] || usage_error "--port must be between 1 and 65535"
    [[ -d "$CORE_PATH/packages/core" ]] || usage_error "--core-path $CORE_PATH has no packages/core directory"
    [[ -z "$FROM" || "$FROM" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || usage_error "--from must be a released version such as 1.0.79"
    [[ -z "$INJECT" || "$INJECT" == "broken-step" || "$INJECT" == "destructive-migration" ]] ||
        usage_error "--inject must be broken-step or destructive-migration"

    build_steps
}

build_steps() {
    STEPS=(
        "Create an app on the published release|step_create_published_app"
        "Install Capell with demo content|step_install_demo_site"
        "Record the site before the upgrade|step_record_before"
        "Update Composer to the Core under test|step_update_composer"
        "Run capell:upgrade|step_run_upgrade"
        "A second upgrade has nothing left to do|step_upgrade_is_settled"
        "Site data survived|step_data_survived"
        "Rebuild assets and runtime caches|step_refresh_runtime"
        "Public pages render as before|step_public_pages_render"
        "Admin pages render as before|step_admin_pages_render"
        "Nothing was logged as an error|step_no_errors_logged"
        "The administrator signs in as before|step_sign_in_as_before"
    )

    # A seeded fault already ends the run red, so the closing proofs would never be reached.
    if ((SELF_CHECK == 1)) && [[ -z "$INJECT" ]]; then
        STEPS+=(
            "Proof: a broken upgrade step fails the upgrade|step_proof_broken_step"
            "Proof: a destructive migration is reported|step_proof_destructive_migration"
        )
    fi
}

now_ms() {
    perl -MTime::HiRes=time -e 'printf "%d", time() * 1000'
}

format_seconds() {
    awk -v ms="$1" 'BEGIN { printf "%.1f s", ms / 1000 }'
}

require_tools() {
    local tool
    for tool in php composer curl perl awk openssl npm; do
        command -v "$tool" >/dev/null 2>&1 || {
            printf 'upgrade-path: %s is required but not installed\n' "$tool" >&2
            exit 3
        }
    done
}

# The newest stable version in `composer show --all --format=json` output on stdin; prints
# nothing when there is none.
latest_stable_version() {
    php -r '
        $versions = json_decode(stream_get_contents(STDIN), true)["versions"] ?? [];
        $stable = [];
        foreach ($versions as $version) {
            if (preg_match("/^v?(\d+\.\d+\.\d+)$/", (string) $version, $match) === 1) {
                $stable[] = $match[1];
            }
        }
        usort($stable, "version_compare");
        echo implode("", array_slice($stable, -1)), PHP_EOL;
    '
}

resolve_from_version() {
    [[ -n "$FROM" ]] && return 0
    FROM="$(composer show --all --format=json --no-interaction capell-app/core 2>/dev/null | latest_stable_version)" || true
    [[ -n "$FROM" ]] || {
        echo "could not read the newest capell-app/core release from Packagist; pass --from"
        return 1
    }
}

prepare_workdir() {
    if [[ -z "$WORKDIR" ]]; then
        WORKDIR="$(mktemp -d "${TMPDIR:-/tmp}/upgrade-path.XXXXXX")"
        AUTO_WORKDIR=1
    else
        mkdir -p "$WORKDIR"
    fi
    WORKDIR="$(cd "$WORKDIR" && pwd)"
    [[ ! -e "$WORKDIR/app" ]] || usage_error "--workdir $WORKDIR already holds an app from an earlier run"
    APP_DIR="$WORKDIR/app"
    LOG_DIR="$WORKDIR/logs"
    EVIDENCE_DIR="$WORKDIR/evidence"
    mkdir -p "$LOG_DIR" "$EVIDENCE_DIR"
}

# Steps run in subshells, so the server's process id is kept in a file.
stop_server() {
    local pid_file="$WORKDIR/server.pid" pid
    [[ -n "$WORKDIR" && -f "$pid_file" ]] || return 0
    pid="$(cat "$pid_file")"
    rm -f "$pid_file"
    # `php artisan serve` starts a child PHP server; stop both.
    pkill -P "$pid" 2>/dev/null || true
    kill "$pid" 2>/dev/null || true
}

start_server() {
    local attempt pid
    stop_server
    if curl -s -o /dev/null --max-time 2 "http://127.0.0.1:$PORT/"; then
        echo "port $PORT is already serving something else"
        return 1
    fi
    (cd "$APP_DIR" && exec php artisan serve --no-reload --host=127.0.0.1 --port="$PORT" >>"$LOG_DIR/serve.log" 2>&1) &
    pid=$!
    echo "$pid" >"$WORKDIR/server.pid"
    for attempt in $(seq 1 60); do
        kill -0 "$pid" 2>/dev/null || {
            echo "php artisan serve exited; see $LOG_DIR/serve.log"
            return 1
        }
        if curl -s -o /dev/null --max-time 5 "http://127.0.0.1:$PORT/"; then
            return 0
        fi
        sleep 0.5
    done
    echo "the server did not answer within 30 s (attempt $attempt)"
    return 1
}

cleanup() {
    stop_server
    if [[ "$KEEP" -eq 0 && -n "$APP_DIR" && -d "$APP_DIR" ]]; then
        rm -rf "$APP_DIR"
    fi
    if [[ "$AUTO_WORKDIR" -eq 1 && "$KEEP" -eq 0 && -z "$FAILED_STEP" && -n "$WORKDIR" ]]; then
        rm -rf "$WORKDIR"
    fi
}

foundation_packages() {
    local package
    for package in "$CORE_PATH"/packages/*/; do
        php -r 'echo json_decode(file_get_contents($argv[1]), true)["name"] ?? "", PHP_EOL;' "${package}composer.json"
    done
}

path_repository_json() {
    local versions="" name
    while IFS= read -r name; do
        [[ -n "$name" ]] || continue
        versions="${versions:+$versions,}\"$name\":\"$PATH_VERSION\""
    done < <(foundation_packages)
    printf '{"type":"path","url":"%s/packages/*","options":{"symlink":false,"versions":{%s}}}' "$CORE_PATH" "$versions"
}

installed_core_version() {
    (cd "$APP_DIR" && composer show --format=json --no-interaction capell-app/core) |
        php -r 'echo ltrim((string) (json_decode(stream_get_contents(STDIN), true)["versions"][0] ?? ""), "v"), PHP_EOL;'
}

artisan() {
    (cd "$APP_DIR" && php artisan "$@")
}

# The admin panel's path, read from its sign-in route so a renamed panel path is followed.
admin_path() {
    local path
    path="$(artisan route:list --json | php -r '
        foreach (json_decode(stream_get_contents(STDIN), true) as $route) {
            if (preg_match("/^filament\.[^.]+\.auth\.login$/", (string) ($route["name"] ?? "")) === 1) {
                echo "/", trim(dirname((string) $route["uri"]), "/"), PHP_EOL;
                break;
            }
        }
    ')"
    [[ -n "$path" ]] || {
        echo "no admin sign-in route is registered" >&2
        return 1
    }
    printf '%s\n' "$path"
}

# Every admin page that needs no record id: resource lists, create forms, dashboards, settings.
admin_page_uris() {
    artisan route:list --json | php -r '
        foreach (json_decode(stream_get_contents(STDIN), true) as $route) {
            $name = (string) ($route["name"] ?? "");
            $uri = (string) $route["uri"];
            if (str_starts_with($name, "filament.") && str_contains((string) $route["method"], "GET")
                && ! str_contains($uri, "{") && ! str_contains($name, ".auth.")) {
                echo $uri, PHP_EOL;
            }
        }
    ' | sort -u
}

error_log_lines() {
    local log
    for log in "$APP_DIR"/storage/logs/*.log; do
        [[ -f "$log" ]] || continue
        grep -E "$ERROR_LEVELS" "$log" || true
    done
}

seed_broken_step() {
    local providers="$APP_DIR/bootstrap/providers.php"
    cp "$HELPERS/fixtures/UpgradePathBrokenStepServiceProvider.php" "$APP_DIR/app/Providers/"
    cp "$providers" "$WORKDIR/providers.php.before-fault"
    php -r '
        $providers = require $argv[1];
        $providers[] = "App\\Providers\\UpgradePathBrokenStepServiceProvider";
        file_put_contents($argv[1], "<?php\n\nreturn " . var_export($providers, true) . ";\n");
    ' "$providers"
    # Capell's runtime cache lists the application's providers; a deploy rebuilds it.
    artisan capell:package-cache >>"$LOG_DIR/seeded-step.log" 2>&1
}

remove_broken_step() {
    cp "$WORKDIR/providers.php.before-fault" "$APP_DIR/bootstrap/providers.php"
    # Rebuild the runtime cache while the class still exists: the cache names it, and the
    # application cannot boot to rebuild a cache that names a missing provider.
    artisan capell:package-cache >>"$LOG_DIR/seeded-step.log" 2>&1
    rm -f "$APP_DIR/app/Providers/UpgradePathBrokenStepServiceProvider.php"
}

# Drops a populated column chosen from the pre-upgrade snapshot, as a careless migration would.
run_destructive_migration() {
    local table column migration="database/migrations/2099_01_01_000000_upgrade_path_drop_populated_column.php"
    read -r table column < <(php "$HELPERS/database.php" pick-column "$EVIDENCE_DIR/database-before.json" "$HELPERS/allowed-changes.json")
    echo "seeded destructive migration drops $table.$column"
    cp "$HELPERS/fixtures/drop_populated_column.php" "$APP_DIR/$migration"
    UPGRADE_PATH_DROP_TABLE="$table" UPGRADE_PATH_DROP_COLUMN="$column" \
        artisan migrate --force --path="$migration" >>"$LOG_DIR/seeded-migration.log" 2>&1
}

step_create_published_app() {
    local name
    resolve_from_version
    echo "upgrading from capell-app/core $FROM"
    echo "$FROM" >"$EVIDENCE_DIR/from-version"
    composer create-project "$SKELETON" "$APP_DIR" \
        --stability=dev --no-install --no-scripts --no-interaction --no-progress >"$LOG_DIR/create-project.log" 2>&1
    cd "$APP_DIR"
    while IFS= read -r name; do
        [[ -n "$name" ]] || continue
        composer require --no-update --no-interaction "$name:$FROM" >>"$LOG_DIR/composer-published.log" 2>&1
    done < <(foundation_packages)
    composer update --no-interaction --no-progress >>"$LOG_DIR/composer-published.log" 2>&1
    [[ -f .env ]] || cp .env.example .env
    composer run-script post-create-project-cmd --no-interaction >>"$LOG_DIR/composer-published.log" 2>&1
    [[ "$(installed_core_version)" == "$FROM" ]] || {
        echo "expected capell-app/core $FROM, Composer installed $(installed_core_version)"
        return 1
    }
}

step_install_demo_site() {
    artisan capell:install --no-interaction --demo \
        --url="http://127.0.0.1:$PORT" \
        --name="Upgrade Path Check" \
        --email="$ADMIN_EMAIL" \
        --password="$(openssl rand -hex 16)" >"$LOG_DIR/install.log" 2>&1
    # The scratch app gains two routes that let the check act as this administrator; see the
    # fixture for why. They exist on both sides of the upgrade, so they cannot mask a change.
    cp "$HELPERS/fixtures/sign-in-route.php" "$APP_DIR/routes/upgrade-path-sign-in.php"
    printf "\nrequire __DIR__.'/upgrade-path-sign-in.php';\n" >>"$APP_DIR/routes/web.php"
    printf '%s' "$ADMIN_EMAIL" >"$APP_DIR/storage/app/upgrade-path-admin-email"
}

# Whether the administrator can sign in through the guard, as "200" or the failing status.
sign_in_status() {
    curl -s -o /dev/null -w '%{http_code}' --max-time 60 "http://127.0.0.1:$PORT/upgrade-path-check/sign-in"
}

warn() {
    printf 'WARNING: %s\n' "$1"
    printf '%s\n' "$1" >>"$EVIDENCE_DIR/warnings.txt"
}

# Starts an administrator session in a fresh cookie jar; the server must be running.
administrator_session() { # cookie jar
    rm -f "$1"
    [[ "$(curl -s -o /dev/null -w '%{http_code}' --max-time 60 -c "$1" "http://127.0.0.1:$PORT/upgrade-path-check/session")" == "200" ]] || {
        echo "no session could be started for the administrator $ADMIN_EMAIL"
        return 1
    }
}

step_record_before() {
    local base="http://127.0.0.1:$PORT" jar="$WORKDIR/admin-cookies.txt" rendering
    php "$HELPERS/database.php" page-paths "$APP_DIR/database/database.sqlite" >"$EVIDENCE_DIR/page-paths.txt"
    admin_page_uris >"$EVIDENCE_DIR/admin-paths-before.txt"
    start_server
    php "$HELPERS/pages.php" crawl "$base" "$EVIDENCE_DIR/pages-before.json" "$(admin_path)" "$EVIDENCE_DIR/page-paths.txt" | tee "$LOG_DIR/pages-before.log"
    administrator_session "$jar"
    php "$HELPERS/pages.php" record "$base" "$EVIDENCE_DIR/admin-before.json" "$EVIDENCE_DIR/admin-paths-before.txt" "$jar" >"$LOG_DIR/admin-before.log"
    tail -n 1 "$LOG_DIR/admin-before.log"
    sign_in_status >"$EVIDENCE_DIR/sign-in-before"
    stop_server
    echo "administrator sign-in on the published release: status $(cat "$EVIDENCE_DIR/sign-in-before")"
    # A site with no content or no working admin cannot show that an upgrade broke either.
    rendering="$(grep -c '^page ' "$LOG_DIR/pages-before.log" || true)"
    ((rendering >= 5)) || {
        echo "the demo install published only $rendering public page(s); the check needs content to protect"
        return 1
    }
    rendering="$(grep -c '^page ' "$LOG_DIR/admin-before.log" || true)"
    ((rendering >= 10)) || {
        echo "only $rendering admin page(s) render on the published release; the check needs an admin to protect"
        return 1
    }
    php "$HELPERS/database.php" snapshot "$APP_DIR/database/database.sqlite" "$EVIDENCE_DIR/database-before.json"
}

step_update_composer() {
    cd "$APP_DIR"
    composer config --json repositories.capell-core "$(path_repository_json)"
    # shellcheck disable=SC2046 # one argument per foundation package
    composer require --no-update --no-interaction $(foundation_packages | sed 's/$/:^1.0/') >"$LOG_DIR/composer-upgrade.log" 2>&1
    composer update --with-all-dependencies --no-interaction --no-progress >>"$LOG_DIR/composer-upgrade.log" 2>&1
    [[ "$(installed_core_version)" == "$PATH_VERSION" ]] || {
        echo "Composer did not install Core from $CORE_PATH (found $(installed_core_version))"
        return 1
    }
    # Errors from here on belong to the upgrade; keep what the published release logged apart.
    mkdir -p "$LOG_DIR/before-upgrade"
    find storage/logs -maxdepth 1 -name '*.log' -exec mv {} "$LOG_DIR/before-upgrade/" \;
    if [[ "$INJECT" == "broken-step" ]]; then
        seed_broken_step
        echo "seeded a broken upgrade step"
    fi
}

step_run_upgrade() {
    local status=0
    artisan capell:upgrade --force --no-interaction --dry-run >"$LOG_DIR/upgrade-dry-run.log" 2>&1
    artisan capell:upgrade --force --no-interaction >"$LOG_DIR/upgrade.log" 2>&1 || status=$?
    grep -E 'Running:|Done|Skipped|Failed|exit=' "$LOG_DIR/upgrade.log" || true
    ((status == 0)) || {
        echo "capell:upgrade exited $status"
        return 1
    }
    if [[ "$INJECT" == "destructive-migration" ]]; then
        run_destructive_migration
    fi
}

step_upgrade_is_settled() {
    artisan capell:upgrade --force --no-interaction >"$LOG_DIR/upgrade-second.log" 2>&1
    grep -q 'No pending upgrade steps.' "$LOG_DIR/upgrade-second.log" || {
        echo "upgrade steps were still pending after a successful upgrade"
        return 1
    }
    artisan migrate:status --no-interaction >"$LOG_DIR/migrate-status.log" 2>&1
    if grep -q 'Pending' "$LOG_DIR/migrate-status.log"; then
        echo "migrations were still pending after a successful upgrade:"
        grep 'Pending' "$LOG_DIR/migrate-status.log"
        return 1
    fi
}

step_data_survived() {
    php "$HELPERS/database.php" snapshot "$APP_DIR/database/database.sqlite" "$EVIDENCE_DIR/database-after.json"
    php "$HELPERS/database.php" compare "$EVIDENCE_DIR/database-before.json" "$EVIDENCE_DIR/database-after.json" "$HELPERS/allowed-changes.json"
}

step_refresh_runtime() {
    cd "$APP_DIR"
    npm ci --no-audit --no-fund >"$LOG_DIR/runtime-refresh.log" 2>&1
    php artisan capell:frontend-after-install --apply --no-interaction >>"$LOG_DIR/runtime-refresh.log" 2>&1
    php artisan capell:runtime-refresh --no-interaction >>"$LOG_DIR/runtime-refresh.log" 2>&1
}

step_public_pages_render() {
    start_server
    php "$HELPERS/pages.php" recheck "http://127.0.0.1:$PORT" "$EVIDENCE_DIR/pages-before.json" "$HELPERS/allowed-changes.json" public | tee "$LOG_DIR/pages-after.log"
    stop_server
}

step_admin_pages_render() {
    local base="http://127.0.0.1:$PORT" jar="$WORKDIR/admin-cookies.txt" admin
    admin="$(admin_path)"
    admin_page_uris >"$EVIDENCE_DIR/admin-paths-after.txt"
    start_server
    [[ "$(curl -s -o /dev/null -w '%{http_code}' --max-time 60 "$base$admin/login")" == "200" ]] || {
        echo "the admin sign-in page does not render"
        return 1
    }
    administrator_session "$jar"
    php "$HELPERS/pages.php" recheck "$base" "$EVIDENCE_DIR/admin-before.json" "$HELPERS/allowed-changes.json" admin "$EVIDENCE_DIR/admin-paths-after.txt" "$jar" | tee "$LOG_DIR/admin-after.log"
    stop_server
}

step_no_errors_logged() {
    local errors
    errors="$(error_log_lines)"
    if [[ -n "$errors" ]]; then
        echo "the upgraded site logged errors:"
        printf '%s\n' "$errors" | cut -c1-400 | head -n 20
        return 1
    fi
}

# Runs after the error-log step: a sign-in that already failed on the published release logs its
# error again here, and that is the published release's defect, not the upgrade's.
step_sign_in_as_before() {
    local before after
    before="$(cat "$EVIDENCE_DIR/sign-in-before")"
    start_server
    after="$(sign_in_status)"
    stop_server
    if [[ "$after" == "200" ]]; then
        echo "the administrator signs in"
    elif [[ "$before" == "200" ]]; then
        echo "the administrator signed in before the upgrade and gets status $after after it"
        return 1
    else
        warn "Administrator sign-in already failed on the published release (status $before) and still does (status $after). Not caused by this upgrade; see $(basename "$LOG_DIR")/before-upgrade for the error."
    fi
}

step_proof_broken_step() {
    local status=0
    seed_broken_step
    artisan capell:upgrade --force --no-interaction >"$LOG_DIR/proof-broken-step.log" 2>&1 || status=$?
    remove_broken_step
    grep -q 'Seeded upgrade-path failure.' "$LOG_DIR/proof-broken-step.log" || {
        echo "the seeded upgrade step never ran, so this proof shows nothing"
        return 1
    }
    ((status != 0)) || {
        echo "capell:upgrade exited 0 although an upgrade step threw"
        return 1
    }
    echo "capell:upgrade exited $status when an upgrade step threw"
}

step_proof_destructive_migration() {
    run_destructive_migration
    php "$HELPERS/database.php" snapshot "$APP_DIR/database/database.sqlite" "$EVIDENCE_DIR/database-after-fault.json" >/dev/null
    if php "$HELPERS/database.php" compare "$EVIDENCE_DIR/database-before.json" "$EVIDENCE_DIR/database-after-fault.json" "$HELPERS/allowed-changes.json" >"$LOG_DIR/proof-destructive-migration.log" 2>&1; then
        echo "a migration dropped a populated column and the data check still passed"
        return 1
    fi
    grep 'LOST' "$LOG_DIR/proof-destructive-migration.log"
}

write_summary() { # result line
    local table="" row
    table+=$'### Upgrade path\n\n'
    table+=$'| Step | Result | Time |\n|---|---|---|\n'
    for row in "${ROWS[@]+"${ROWS[@]}"}"; do
        table+="$row"$'\n'
    done
    table+="| **Total** | | **$(format_seconds "$TOTAL_MS")** |"$'\n\n'
    table+="$1"$'\n'
    if [[ -s "${EVIDENCE_DIR:-.}/warnings.txt" ]]; then
        table+=$'\nWarnings:\n\n'
        table+="$(sed 's/^/- /' "$EVIDENCE_DIR/warnings.txt")"$'\n'
    fi
    printf '%s\n' "$table"
    if [[ -n "$SUMMARY" ]]; then
        printf '%s\n' "$table" >>"$SUMMARY"
    fi
}

# Call this as a plain command, never inside `if`, `&&` or `||`: bash ignores `set -e` in those
# contexts, and a step whose middle command failed would then count as passed.
run_pipeline() {
    local entry label func start elapsed status result="" skipping=0 marker log
    ROWS=()
    TOTAL_MS=0
    FAILED_STEP=""

    for entry in "${STEPS[@]}"; do
        label="${entry%%|*}"
        func="${entry#*|}"
        if ((skipping)); then
            ROWS+=("| $label | skipped | |")
            continue
        fi
        printf '==> %s\n' "$label"
        marker="$(mktemp)"
        start="$(now_ms)"
        set +e
        (
            set -e
            "$func"
        )
        status=$?
        set -e
        elapsed=$(($(now_ms) - start))
        TOTAL_MS=$((TOTAL_MS + elapsed))
        if ((status == 0)); then
            ROWS+=("| $label | ok | $(format_seconds "$elapsed") |")
        else
            ROWS+=("| $label | failed | $(format_seconds "$elapsed") |")
            FAILED_STEP="$label"
            result="FAILED: \"$label\" failed. Logs: ${LOG_DIR:-not written}"
            skipping=1
            if [[ -n "$LOG_DIR" ]]; then
                while IFS= read -r log; do
                    printf '\n--- tail of %s ---\n' "$log"
                    tail -n 40 "$log"
                done < <(find "$LOG_DIR" -maxdepth 1 -name '*.log' -newer "$marker" | sort)
            fi
        fi
        rm -f "$marker"
    done

    if [[ -z "$result" ]]; then
        # Steps run in subshells, so the resolved release is read back from its evidence file.
        result="PASSED: the site upgraded cleanly from $(cat "${EVIDENCE_DIR:-.}/from-version" 2>/dev/null || echo 'the published release') in $(format_seconds "$TOTAL_MS")."
    fi
    write_summary "$result"

    [[ -z "$FAILED_STEP" ]]
}

main() {
    parse_args "$@"
    require_tools
    prepare_workdir
    trap cleanup EXIT
    run_pipeline
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
    main "$@"
fi
