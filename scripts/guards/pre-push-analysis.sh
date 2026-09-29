#!/usr/bin/env bash
# Static analysis before a push. Hosted CI is the only other PHPStan gate and is
# often billing-blocked, and the pre-commit hooks only format, so type errors
# otherwise first surface in the release's core.preflight. Analyses the PHP
# files the pushed commits change, with the scoped diff configuration. Callers
# of deleted or renamed files are not re-checked here; the full analysis in
# core.preflight still covers them.
set -eu
cd "$(git rev-parse --show-toplevel)"

# pre-commit supplies the pushed range; PHPStan reads files on disk, so only a
# push of the checked-out commit can be analysed faithfully.
to_ref="${PRE_COMMIT_TO_REF:-HEAD}"
if [ "$(git rev-parse "$to_ref")" != "$(git rev-parse HEAD)" ]; then
    echo "Refused push: the pushed commit is not checked out, so it cannot be analysed. Check it out and push it from there." >&2
    exit 1
fi

base="${CAPELL_PRE_PUSH_ANALYSIS_BASE:-origin/main}"
git rev-parse --verify --quiet "$base" > /dev/null || {
    echo "pre-push analysis: unknown base $base; fetch it or set CAPELL_PRE_PUSH_ANALYSIS_BASE." >&2
    exit 1
}

files=()
while IFS= read -r file; do
    [ -f "$file" ] && files+=("$file")
done < <(git diff --name-only --diff-filter=ACMR "$base...HEAD" | grep -E '^(packages|tests)/.+\.php$|^scripts/benchmark-boot.*\.php$' || true)
if [ "${#files[@]}" -eq 0 ]; then
    exit 0
fi

dirty="$(git diff --name-only HEAD -- "${files[@]}")"
if [ -n "$dirty" ]; then
    printf 'Refused push: these files have uncommitted edits, so the analysis would not check what is being pushed. Commit or stash them first:\n%s\n' "$dirty" >&2
    exit 1
fi

# The default Homebrew PATH selects PHP 8.5; Core supports 8.4.
[ -d /opt/homebrew/opt/php@8.4/bin ] && export PATH="/opt/homebrew/opt/php@8.4/bin:$PATH"

echo "pre-push analysis: PHPStan over ${#files[@]} changed file(s) vs $base"
if ! composer analyze:diff -- "${files[@]}"; then
    echo "Refused push: the analysis failed (PHPStan errors, or its runtime could not start; read the output above). Fix the cause; do not bypass this hook." >&2
    exit 1
fi
