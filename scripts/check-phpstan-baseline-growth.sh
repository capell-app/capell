#!/usr/bin/env bash

set -euo pipefail

base_ref="${PHPSTAN_BASELINE_BASE_REF:-${GITHUB_BASE_REF:-}}"
base_candidate=""

if [ -n "${base_ref}" ]; then
    if git rev-parse --verify --quiet "origin/${base_ref}" >/dev/null; then
        base_candidate="origin/${base_ref}"
    elif git rev-parse --verify --quiet "${base_ref}" >/dev/null; then
        base_candidate="${base_ref}"
    fi
fi

if [ -z "${base_candidate}" ] && git rev-parse --verify --quiet HEAD^ >/dev/null; then
    base_candidate="HEAD^"
fi

if [ -z "${base_candidate}" ]; then
    echo "Skipping PHPStan baseline growth check: no base ref is available."
    exit 0
fi

base_commit="$(git merge-base HEAD "${base_candidate}" 2>/dev/null || git rev-parse "${base_candidate}")"

baseline_files=()

while IFS= read -r baseline_file; do
    baseline_files+=("${baseline_file}")
done < <(find phpstan -maxdepth 1 -type f \( -name '*baseline*.neon' -o -name 'ignore-errors.neon' \) -print 2>/dev/null | sort)

if [ "${#baseline_files[@]}" -eq 0 ]; then
    echo "No PHPStan baseline or ignore-error files found."
    exit 0
fi

count_baseline_debt() {
    php -r '
        $total = 0;

        foreach (array_slice($argv, 1) as $file) {
            $contents = file_get_contents($file);

            if ($contents === false) {
                fwrite(STDERR, "Unable to read {$file}\n");
                return 2;
            }

            preg_match_all("/^\s*count:\s*(\d+)\s*$/m", $contents, $counts);

            if ($counts[1] !== []) {
                foreach ($counts[1] as $count) {
                    $total += (int) $count;
                }

                continue;
            }

            preg_match_all("/^\s*message:/m", $contents, $messages);
            $total += count($messages[0]);
        }

        echo $total;
    ' "$@"
}

tmp_dir="$(mktemp -d)"
trap 'rm -rf "${tmp_dir}"' EXIT

base_files=()

for baseline_file in "${baseline_files[@]}"; do
    base_file="${tmp_dir}/${baseline_file//\//__}"

    if git cat-file -e "${base_commit}:${baseline_file}" 2>/dev/null; then
        git show "${base_commit}:${baseline_file}" > "${base_file}"
    else
        : > "${base_file}"
    fi

    base_files+=("${base_file}")
done

current_debt="$(count_baseline_debt "${baseline_files[@]}")"
base_debt="$(count_baseline_debt "${base_files[@]}")"

echo "PHPStan baseline debt: current=${current_debt}, base=${base_debt}"

if [ "${current_debt}" -gt "${base_debt}" ]; then
    echo "PHPStan baseline grew by $((current_debt - base_debt)) ignored error(s)."
    echo "Fix the new PHPStan issue instead of expanding the baseline."
    exit 1
fi
