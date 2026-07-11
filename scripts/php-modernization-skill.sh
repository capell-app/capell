#!/usr/bin/env bash

set -euo pipefail

COMMAND="${1:-verify}"

if [[ $# -gt 0 ]]; then
  shift
fi

PROJECT_ROOT="${PHP_MODERNIZATION_PROJECT_ROOT:-.}"
PROJECT_ROOT="$(cd "$PROJECT_ROOT" && pwd)"
SKILL_DIR="${PHP_MODERNIZATION_SKILL_DIR:-}"
SKILL_REF="${PHP_MODERNIZATION_SKILL_REF:-main}"
SKILL_CACHE_DIR="${PHP_MODERNIZATION_SKILL_CACHE_DIR:-.cache/php-modernization-skill}"

resolve_skill_dir() {
  if [[ -n "$SKILL_DIR" && -f "$SKILL_DIR/scripts/verify_php_project.py" ]]; then
    return
  fi

  local candidate_dir

  for candidate_dir in \
    "tools/php-modernization-skill/skills/php-modernization" \
    "$SKILL_CACHE_DIR/skills/php-modernization" \
    "$HOME/.agents/skills/php-modernization"; do
    if [[ -f "$candidate_dir/scripts/verify_php_project.py" ]]; then
      SKILL_DIR="$candidate_dir"
      return
    fi
  done

  mkdir -p "$(dirname "$SKILL_CACHE_DIR")"
  rm -rf "$SKILL_CACHE_DIR.tmp"
  mkdir -p "$SKILL_CACHE_DIR.tmp"

  curl -fsSL "https://github.com/netresearch/php-modernization-skill/archive/refs/heads/${SKILL_REF}.tar.gz" \
    | tar -xz --strip-components=1 -C "$SKILL_CACHE_DIR.tmp"

  rm -rf "$SKILL_CACHE_DIR"
  mv "$SKILL_CACHE_DIR.tmp" "$SKILL_CACHE_DIR"

  SKILL_DIR="$SKILL_CACHE_DIR/skills/php-modernization"
}

run_python_script() {
  if command -v uv >/dev/null 2>&1; then
    uv run "$@"
    return
  fi

  if command -v python3 >/dev/null 2>&1; then
    python3 "$@"
    return
  fi

  echo "php-modernization-skill requires uv or python3." >&2
  exit 1
}

prepare_verify_root() {
  local verify_root

  verify_root="$(mktemp -d "${TMPDIR:-/tmp}/php-modernization-verify.XXXXXX")"

  for file_name in composer.json composer.lock .php-cs-fixer.dist.php rector.php; do
    if [[ -f "$PROJECT_ROOT/$file_name" ]]; then
      cp "$PROJECT_ROOT/$file_name" "$verify_root/$file_name"
    fi
  done

  if [[ ! -f "$verify_root/.php-cs-fixer.dist.php" ]]; then
    # The app uses Pint; keep the verifier's PHP-CS-Fixer shim scoped to an empty temp directory.
    mkdir -p "$verify_root/.php-modernization-empty"
    cat > "$verify_root/.php-cs-fixer.dist.php" <<'PHP'
<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__ . '/.php-modernization-empty');

return (new PhpCsFixer\Config())
    ->setRules([
        '@PER-CS' => true,
    ])
    ->setFinder($finder);
PHP
  fi

  if [[ -d "$PROJECT_ROOT/packages" ]]; then
    ln -s "$PROJECT_ROOT/packages" "$verify_root/packages"
  fi

  cat > "$verify_root/phpstan.neon" <<'NEON'
parameters:
    level: max
    treatPhpDocTypesAsCertain: false
NEON

  if [[ -f "$verify_root/composer.json" ]]; then
    php -r '
      $path = $argv[1];
      $composer = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
      $composer["scripts"] ??= [];
      $composer["scripts"]["cs:fix"] = "vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php";
      $composer["scripts"]["cs:check"] = "vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php --dry-run --diff";
      $composer["scripts"]["phpat"] = "vendor/bin/phpstan analyse --configuration=phpstan/phpat.neon";
      $composer["scripts"]["rector"] = "vendor/bin/rector process --no-progress-bar";
      file_put_contents($path, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    ' "$verify_root/composer.json"
  fi

  echo "$verify_root"
}

resolve_skill_dir

case "$COMMAND" in
  inspect)
    run_python_script "$SKILL_DIR/scripts/introspect.py" --root "$PROJECT_ROOT" "$@"
    ;;
  verify)
    VERIFY_ROOT="$(prepare_verify_root)"
    trap 'rm -rf "$VERIFY_ROOT"' EXIT
    run_python_script "$SKILL_DIR/scripts/verify_php_project.py" --root "$VERIFY_ROOT" "$@"
    ;;
  modernize | modernize:dry-run)
    run_python_script "$SKILL_DIR/scripts/modernize_loop.py" --root "$PROJECT_ROOT" "$@"
    ;;
  *)
    echo "Unknown php-modernization-skill command: $COMMAND" >&2
    exit 1
    ;;
esac
