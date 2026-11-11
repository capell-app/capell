#!/usr/bin/env bash
# Time a fresh Capell app from `composer create-project` to its first HTTP 200.
#
# Usage:
#   scripts/first-page-timing.sh [options]
#
# Options:
#   --core-path DIR     Checked-out Core monorepo to install from (default: this repository).
#                       Its packages/* directories are added as path repositories, so the
#                       code under test is what gets installed.
#   --skeleton NAME     Skeleton package for create-project (default: capell-app/capell-skeleton).
#   --budget SECONDS    Fail when the timed steps take longer than this (default: 300).
#   --port PORT         Port for `php artisan serve` on 127.0.0.1 (default: 8000).
#   --workdir DIR       Scratch directory for the app and logs (default: a new temp directory,
#                       removed after a passing run and kept after a failing one).
#   --summary FILE      Append the markdown timing table here
#                       (default: $GITHUB_STEP_SUMMARY when set, otherwise only printed).
#   --keep              Keep the generated app after the run.
#   -h, --help          Show this help.
#
# Steps timed: create-project, dependency install (Core from the path repository, the rest
# from Packagist) with the skeleton's post-create scripts, capell:install, starting the server, and the first GET /.
# Dependencies other than the Core monorepo packages come from Packagist, so the run measures
# what a new user gets with the Core change applied. The default SQLite .env is left alone and
# database.sqlite is created by the installer. Admin credentials are generated per run.
#
# Exit codes: 0 within budget, 1 a step failed or the budget was exceeded, 2 usage error,
# 3 a required tool is missing.
#
# Local run:  heavy run -w 2 first-page -- scripts/first-page-timing.sh
# Test:       bash tests/Shell/first-page-timing.sh

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

CORE_PATH="$(cd "$SCRIPT_DIR/.." && pwd)"
SKELETON="capell-app/capell-skeleton"
BUDGET=300
PORT=8000
WORKDIR=""
SUMMARY="${GITHUB_STEP_SUMMARY:-}"
KEEP=0
# Higher than any released 1.x, so every ^1.0.x constraint in the Packagist packages accepts it.
PATH_VERSION="1.999.0"

STEPS=(
    "composer create-project|step_create_project"
    "Install dependencies (Core from path)|step_install_dependencies"
    "Run capell:install|step_install"
    "Start php artisan serve|step_start_server"
    "GET / returns 200 and renders|step_first_page"
)

AUTO_WORKDIR=0
APP_DIR=""
LOG_DIR=""
SERVER_PID=""
ROWS=()
TOTAL_MS=0
FAILED_STEP=""

usage_error() {
    printf 'first-page-timing: %s\n' "$1" >&2
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
            --core-path | --skeleton | --budget | --port | --workdir | --summary)
                (($# >= 2)) || usage_error "$1 needs a value"
                case "$1" in
                    --core-path) CORE_PATH="$2" ;;
                    --skeleton) SKELETON="$2" ;;
                    --budget) BUDGET="$2" ;;
                    --port) PORT="$2" ;;
                    --workdir) WORKDIR="$2" ;;
                    --summary) SUMMARY="$2" ;;
                esac
                shift 2
                ;;
            *)
                usage_error "unknown option: $1"
                ;;
        esac
    done

    [[ "$BUDGET" =~ ^[0-9]+$ && "$BUDGET" -gt 0 ]] || usage_error "--budget must be a positive whole number of seconds"
    [[ "$PORT" =~ ^[0-9]+$ && "$PORT" -ge 1 && "$PORT" -le 65535 ]] || usage_error "--port must be between 1 and 65535"
    [[ -d "$CORE_PATH/packages/core" ]] || usage_error "--core-path $CORE_PATH has no packages/core directory"
}

now_ms() {
    perl -MTime::HiRes=time -e 'printf "%d", time() * 1000'
}

format_seconds() {
    awk -v ms="$1" 'BEGIN { printf "%.1f s", ms / 1000 }'
}

require_tools() {
    local tool
    for tool in php composer curl perl awk openssl; do
        command -v "$tool" >/dev/null 2>&1 || {
            printf 'first-page-timing: %s is required but not installed\n' "$tool" >&2
            exit 3
        }
    done
}

prepare_workdir() {
    if [[ -z "$WORKDIR" ]]; then
        WORKDIR="$(mktemp -d "${TMPDIR:-/tmp}/first-page-timing.XXXXXX")"
        AUTO_WORKDIR=1
    else
        mkdir -p "$WORKDIR"
    fi
    WORKDIR="$(cd "$WORKDIR" && pwd)"
    APP_DIR="$WORKDIR/app"
    LOG_DIR="$WORKDIR/logs"
    mkdir -p "$LOG_DIR"
}

stop_server() {
    if [[ -n "$SERVER_PID" ]]; then
        # `php artisan serve` starts a child PHP server; stop both.
        pkill -P "$SERVER_PID" 2>/dev/null || true
        kill "$SERVER_PID" 2>/dev/null || true
        wait "$SERVER_PID" 2>/dev/null || true
        SERVER_PID=""
    fi
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

path_repository_json() {
    local versions="" package name
    for package in "$CORE_PATH"/packages/*/; do
        name="$(php -r 'echo json_decode(file_get_contents($argv[1]), true)["name"] ?? "";' "${package}composer.json")"
        [[ -n "$name" ]] || continue
        versions="${versions:+$versions,}\"$name\":\"$PATH_VERSION\""
    done
    printf '{"type":"path","url":"%s/packages/*","options":{"symlink":false,"versions":{%s}}}' "$CORE_PATH" "$versions"
}

step_create_project() {
    # Scripts are deferred to the later steps so each phase is timed on its own.
    composer create-project "$SKELETON" "$APP_DIR" \
        --stability=dev --no-install --no-scripts --no-interaction --no-progress >"$LOG_DIR/create-project.log" 2>&1
}

step_install_dependencies() {
    (
        cd "$APP_DIR"
        composer config --json repositories.capell-core "$(path_repository_json)"
        composer update --no-interaction --no-progress
        [[ -f .env ]] || cp .env.example .env
        composer run-script post-create-project-cmd --no-interaction
    ) >"$LOG_DIR/composer-install.log" 2>&1
}

step_install() {
    local password
    password="$(openssl rand -hex 16)"
    (
        cd "$APP_DIR"
        php artisan capell:install --no-interaction \
            --url="http://127.0.0.1:$PORT" \
            --name="First Page Check" \
            --email="first-page-$(openssl rand -hex 4)@example.test" \
            --password="$password" >"$LOG_DIR/install.log" 2>&1
    )
}

step_start_server() {
    if curl -s -o /dev/null --max-time 2 "http://127.0.0.1:$PORT/"; then
        echo "port $PORT is already serving something else" >"$LOG_DIR/serve.log"
        return 1
    fi
    (cd "$APP_DIR" && exec php artisan serve --host=127.0.0.1 --port="$PORT" >"$LOG_DIR/serve.log" 2>&1) &
    SERVER_PID=$!
    local attempt
    for attempt in $(seq 1 60); do
        kill -0 "$SERVER_PID" 2>/dev/null || return 1
        if curl -s -o /dev/null --max-time 2 "http://127.0.0.1:$PORT/"; then
            return 0
        fi
        sleep 0.5
    done
    echo "server did not answer within 30 s" >>"$LOG_DIR/serve.log"
    return 1
}

step_first_page() {
    local status type body="$LOG_DIR/first-page.html"
    status="$(curl -s -o "$body" -w '%{http_code} %{content_type}' --max-time 60 "http://127.0.0.1:$PORT/")"
    type="${status#* }"
    status="${status%% *}"
    printf 'status=%s content-type=%s bytes=%s\n' "$status" "$type" "$(wc -c <"$body" | tr -d ' ')" >"$LOG_DIR/first-page.log"
    [[ "$status" == "200" ]] || return 1
    [[ "$type" == text/html* ]] || return 1
    grep -qi '<html' "$body" || return 1
    grep -qi '</html>' "$body" || return 1
    if grep -qiE 'Whoops|Server Error|Stack trace' "$body"; then
        echo "response body looks like an error page" >>"$LOG_DIR/first-page.log"
        return 1
    fi
}

write_summary() { # result line
    local table="" row
    table+=$'### First page timing\n\n'
    table+=$'| Step | Result | Time |\n|---|---|---|\n'
    for row in "${ROWS[@]+"${ROWS[@]}"}"; do
        table+="$row"$'\n'
    done
    table+="| **Total** | | **$(format_seconds "$TOTAL_MS")** (budget ${BUDGET} s) |"$'\n\n'
    table+="$1"$'\n'
    printf '%s\n' "$table"
    if [[ -n "$SUMMARY" ]]; then
        printf '%s\n' "$table" >>"$SUMMARY"
    fi
}

run_pipeline() {
    local entry label func start end elapsed result="" budget_ms=$((BUDGET * 1000))
    local skipping=0
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
        start="$(now_ms)"
        if "$func"; then
            end="$(now_ms)"
            elapsed=$((end - start))
            TOTAL_MS=$((TOTAL_MS + elapsed))
            ROWS+=("| $label | ok | $(format_seconds "$elapsed") |")
            if ((TOTAL_MS > budget_ms)); then
                FAILED_STEP="$label"
                result="FAILED: the timed steps exceeded the ${BUDGET} s budget after \"$label\"."
                skipping=1
            fi
        else
            end="$(now_ms)"
            elapsed=$((end - start))
            TOTAL_MS=$((TOTAL_MS + elapsed))
            ROWS+=("| $label | failed | $(format_seconds "$elapsed") |")
            FAILED_STEP="$label"
            result="FAILED: \"$label\" failed. Logs: ${LOG_DIR:-not written}"
            skipping=1
        fi
    done

    if [[ -z "$result" ]]; then
        result="PASSED: first page served in $(format_seconds "$TOTAL_MS"), within the ${BUDGET} s budget."
    fi
    write_summary "$result"

    if [[ -n "$FAILED_STEP" ]]; then
        local log
        if [[ -n "$LOG_DIR" ]]; then
            for log in "$LOG_DIR"/*.log; do
                [[ -f "$log" ]] || continue
                printf '\n--- tail of %s ---\n' "$log"
                tail -n 25 "$log"
            done
        fi
        return 1
    fi
    return 0
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
