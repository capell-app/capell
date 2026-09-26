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

            $lines = preg_split("/\R/", $contents) ?: [];
            $lineCount = count($lines);

            for ($lineIndex = 0; $lineIndex < $lineCount; $lineIndex++) {
                if (preg_match("/^(\s*)ignoreErrors:\s*(?:#.*)?$/", $lines[$lineIndex], $section) !== 1) {
                    continue;
                }

                $sectionIndent = strlen(str_replace("\t", "    ", $section[1]));
                $entries = [];

                for ($lineIndex++; $lineIndex < $lineCount; $lineIndex++) {
                    $line = $lines[$lineIndex];

                    if (trim($line) === "" || str_starts_with(ltrim($line), "#")) {
                        continue;
                    }

                    preg_match("/^(\s*)/", $line, $indentation);
                    $indent = strlen(str_replace("\t", "    ", $indentation[1]));

                    if ($indent <= $sectionIndent) {
                        $lineIndex--;
                        break;
                    }

                    $entries[] = [$line, $indent];
                }

                $entryIndent = null;

                foreach ($entries as [$line, $indent]) {
                    if (preg_match("/^\s*-\s*/", $line) === 1) {
                        $entryIndent = $entryIndent === null ? $indent : min($entryIndent, $indent);
                    }
                }

                if ($entryIndent === null) {
                    continue;
                }

                $entryStarted = false;
                $entryCount = null;

                foreach ($entries as [$line, $indent]) {
                    if ($indent === $entryIndent && preg_match("/^\s*-\s*/", $line) === 1) {
                        if ($entryStarted) {
                            $total += $entryCount ?? 1;
                        }

                        $entryStarted = true;
                        $entryCount = preg_match("/\bcount:\s*(\d+)\b/", $line, $inlineCount) === 1
                            ? (int) $inlineCount[1]
                            : null;

                        continue;
                    }

                    if ($entryStarted
                        && $indent > $entryIndent
                        && preg_match("/^\s*count:\s*(\d+)\s*(?:#.*)?$/", $line, $count) === 1) {
                        $entryCount = (int) $count[1];
                    }
                }

                if ($entryStarted) {
                    $total += $entryCount ?? 1;
                }
            }
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
