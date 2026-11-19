#!/usr/bin/env bash

# check-php-syntax.sh: fail when any given PHP file no longer parses.
# Usage: ./scripts/check-php-syntax.sh <file.php>...
#
# lint:changed runs Pint, and authors often follow it with Rector. A formatter
# rule pair once turned (new X)->m() into new X->m(), a parse error that only
# PHPStan caught much later. Checking syntax straight after formatting catches
# that class of rewrite at the step that caused it.

set -uo pipefail

PHP_BINARY="${PHP_BINARY:-php}"
FAILED=0

for file in "$@"; do
  [[ -f $file ]] || continue

  if ! output="$("$PHP_BINARY" -l "$file" 2>&1)"; then
    echo "PHP syntax error after formatting: $file" >&2
    echo "$output" >&2
    FAILED=1
  fi
done

exit "$FAILED"
