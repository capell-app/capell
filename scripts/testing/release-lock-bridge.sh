#!/usr/bin/env bash

# The container temporary directory cannot see the host's release lock. Keep
# the host lease alive for the complete Compose command and bypass only the
# container's duplicate lease, never the host gate.
capell_verification_command() {
    local scope="$1"
    shift
    if [ "$scope" = full ]; then
        python3 "$CAPELL_CHECKOUT_PATH/scripts/testing/with-release-lock.py" -- "$@"
    else
        "$@"
    fi
}

capell_verification_exec() {
    local scope="$1"
    shift
    local release_environment=()
    if [ "$scope" = full ]; then
        release_environment=(-e CAPELL_NO_RELEASE_LOCK=1)
    fi
    capell_verification_command "$scope" "${DOCKER_COMPOSE[@]}" exec "${release_environment[@]}" "$@"
}

capell_test_verification_scope() {
    local argument files=0 directories=0 skip_value=0
    for argument in "$@"; do
        if [ "$skip_value" = 1 ]; then
            skip_value=0
            continue
        fi
        case "$argument" in
            --configuration|-c|--bootstrap|--filter|--testsuite|--group|--exclude-group) skip_value=1 ;;
            tests/*.php|--path=tests/*.php|--path=*/tests/*.php) files=$((files + 1)) ;;
            --path=tests/*|--path=*/tests/*) directories=$((directories + 1)) ;;
            -*) continue ;;
            */tests/*.php) files=$((files + 1)) ;;
            tests/*|*/tests/*) directories=$((directories + 1)) ;;
            *) if [ -d "$argument" ]; then directories=$((directories + 1)); fi ;;
        esac
    done
    if [ "$files" = 1 ] && [ "$directories" = 0 ]; then
        printf 'focused\n'
    else
        printf 'full\n'
    fi
}

capell_composer_verification_scope() {
    local argument
    for argument in "$@"; do
        case "$argument" in
            -*) continue ;;
            run|run-script) continue ;;
            test*|preflight*|premerge:lanes|coverage*|release-contracts|security:contracts) printf 'full\n'; return ;;
            *) break ;;
        esac
    done
    printf 'focused\n'
}

capell_command_verification_scope() {
    case "${1:-}" in
        composer) shift; capell_composer_verification_scope "$@"; return ;;
        vendor/bin/pest|vendor/bin/phpunit) shift; capell_test_verification_scope "$@"; return ;;
    esac
    local argument previous=''
    for argument in "$@"; do
        case "$argument" in
            scripts/preflight.sh|scripts/preflight-all.php|scripts/run-preflight.php|scripts/run-pest-shards.php|scripts/run-package-unit-batch.php|scripts/run-test-all-matrix.php|capell-release-verification)
                printf 'full\n'; return ;;
            vendor/bin/pest|vendor/bin/phpunit|scripts/run-tests.php)
                capell_test_verification_scope "$@"; return ;;
            test)
                if [ "$previous" = artisan ]; then
                    capell_test_verification_scope "$@"; return
                fi
                ;;
        esac
        previous="$argument"
    done
    printf 'focused\n'
}
