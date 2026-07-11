#!/usr/bin/env bash

# lint-changed.sh: Lint changed files.
# Usage: ./scripts/lint-changed.sh [--staged|--committed]

set -eo pipefail


MODE="committed"
if [[ $# -ge 1 && "$1" == "--staged" ]]; then
  MODE="staged"
fi

CHANGED_FILES=()

if [[ "$MODE" == "staged" ]]; then
  while IFS= read -r file; do
    CHANGED_FILES+=("$file")
  done < <(git diff --cached --name-only)
else
  while IFS= read -r file; do
    CHANGED_FILES+=("$file")
  done < <(git diff --name-only HEAD)
fi

PHP_FILES=()
JS_FILES=()
PRETTIER_FILES=()

for file in "${CHANGED_FILES[@]}"; do
  if [[ ! -f $file ]]; then
    continue
  fi
  if [[ $file == packages/*/publishes/build/* ]]; then
    continue
  fi
  if [[ $file == *.php ]]; then
    PHP_FILES+=("$file")
  fi
  if [[ $file == *.js || $file == *.jsx || $file == *.ts || $file == *.tsx ]]; then
    JS_FILES+=("$file")
  fi
  if [[ $file == *.js || $file == *.jsx || $file == *.ts || $file == *.tsx || $file == *.css || $file == *.json || $file == *.yml || $file == *.md || $file == *.blade.php ]]; then
    PRETTIER_FILES+=("$file")
  fi
done

if [[ ${#PHP_FILES[@]} -gt 0 ]]; then
  echo "Running Pint on changed PHP files..."
  ./vendor/bin/pint --parallel "${PHP_FILES[@]}"
else
  echo "No changed PHP files for Pint."
fi

if [[ ${#PRETTIER_FILES[@]} -gt 0 ]]; then
  echo "Running Prettier on changed files..."
  npx prettier --write "${PRETTIER_FILES[@]}"
else
  echo "No changed files for Prettier."
fi

if [[ ${#JS_FILES[@]} -gt 0 ]]; then
  echo "Running ESLint on changed JS/TS files..."
  npx eslint "${JS_FILES[@]}" --max-warnings=0
else
  echo "No changed JS/TS files for ESLint."
fi

echo "Lint complete."
