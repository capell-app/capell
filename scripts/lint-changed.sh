#!/usr/bin/env bash

# lint-changed.sh: Lint changed files.
# Usage: ./scripts/lint-changed.sh [--staged|--committed|--base <git-ref>]
#
# This script is kept byte-for-byte identical in capell-4 and capell-packages-4.
# The two repositories share pre-commit habits, so any behavioural difference
# between the copies surfaces as "it passed locally" on whichever repository the
# author happened not to run.

set -eo pipefail

MODE="committed"
BASE_REF=""

if [[ $# -ge 1 ]]; then
  case "$1" in
    --staged)
      MODE="staged"
      ;;
    --committed)
      MODE="committed"
      ;;
    --base)
      if [[ $# -lt 2 || -z "$2" ]]; then
        echo "--base requires a Git ref." >&2
        exit 2
      fi

      MODE="base"
      BASE_REF="$2"
      ;;
    *)
      echo "Unknown lint mode: $1" >&2
      exit 2
      ;;
  esac
fi

CHANGED_FILES=()

if [[ "$MODE" == "staged" ]]; then
  while IFS= read -r file; do
    CHANGED_FILES+=("$file")
  done < <(git diff --cached --name-only --diff-filter=ACMRT)
elif [[ "$MODE" == "base" ]]; then
  while IFS= read -r file; do
    CHANGED_FILES+=("$file")
  done < <(git diff --name-only --diff-filter=ACMRT "${BASE_REF}...HEAD")
else
  while IFS= read -r file; do
    CHANGED_FILES+=("$file")
  done < <(git diff --name-only --diff-filter=ACMRT HEAD)
fi

PHP_FILES=()
JS_FILES=()
PRETTIER_FILES=()

if [[ ${#CHANGED_FILES[@]} -gt 0 ]]; then
  for file in "${CHANGED_FILES[@]}"; do
    if [[ ! -f $file ]]; then
      continue
    fi
    # Compiled asset output is generated, not authored; formatting it produces
    # a diff that the next build immediately reverts.
    if [[ $file == packages/*/publishes/build/* ]]; then
      continue
    fi
    if [[ $file == *.php && $file != *.blade.php ]]; then
      PHP_FILES+=("$file")
    fi
    if [[ $file == *.js || $file == *.mjs || $file == *.jsx || $file == *.ts || $file == *.tsx ]]; then
      JS_FILES+=("$file")
    fi
    if [[ $file == *.js || $file == *.mjs || $file == *.jsx || $file == *.ts || $file == *.tsx || $file == *.css || $file == *.json || $file == *.yml || $file == *.md || $file == *.blade.php ]]; then
      PRETTIER_FILES+=("$file")
    fi
  done
fi

if [[ ${#PHP_FILES[@]} -gt 0 ]]; then
  echo "Running Pint on changed PHP files..."
  PINT_BINARY="./vendor/bin/pint"

  if [[ ! -x "$PINT_BINARY" ]]; then
    PINT_BINARY="$(command -v pint || true)"
  fi

  if [[ -z "$PINT_BINARY" ]]; then
    echo "Pint is required to format changed PHP files." >&2
    exit 1
  fi

  "$PINT_BINARY" --parallel "${PHP_FILES[@]}"
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
