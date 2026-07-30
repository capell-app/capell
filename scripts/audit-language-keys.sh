#!/usr/bin/env bash

# Audit package translation files for unused and missing static keys.
# Usage: ./scripts/audit-language-keys.sh [--root=packages] [--format=text|json] [--strict]

set -euo pipefail

ROOTS=("packages")
FORMAT="text"
STRICT=0
CUSTOM_ROOT=0

for argument in "$@"; do
  case "$argument" in
    --root=*)
      ROOTS=("${argument#*=}")
      CUSTOM_ROOT=1
      ;;
    --format=*)
      FORMAT="${argument#*=}"
      ;;
    --strict)
      STRICT=1
      ;;
    --help|-h)
      sed -n '2,9p' "$0"
      exit 0
      ;;
    *)
      echo "Unknown argument: $argument" >&2
      exit 2
      ;;
  esac
done

for root in "${ROOTS[@]}"; do
  if [[ ! -d "$root" ]]; then
    echo "Language audit root does not exist: $root" >&2
    exit 2
  fi
done

AUDIT_ROOTS="$(IFS=:; echo "${ROOTS[*]}")" AUDIT_FORMAT="$FORMAT" AUDIT_STRICT="$STRICT" php <<'PHP'
<?php

declare(strict_types=1);

$roots = array_filter(explode(PATH_SEPARATOR, getenv('AUDIT_ROOTS') ?: 'packages'));
$format = getenv('AUDIT_FORMAT') ?: 'text';
$strict = (getenv('AUDIT_STRICT') ?: '0') === '1';
$basePath = getcwd();
$rootPaths = array_map(static fn (string $root): string|false => realpath($root), $roots);

if (in_array(false, $rootPaths, true)) {
    fwrite(STDERR, "Unable to resolve one or more audit roots.\n");
    exit(2);
}

/**
 * @return list<string>
 */
function auditFiles(string $rootPath): array
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($rootPath, FilesystemIterator::SKIP_DOTS),
            static function (SplFileInfo $file): bool {
                if (! $file->isDir()) {
                    return true;
                }

                return ! in_array($file->getFilename(), [
                    '.git',
                    '.phpunit.cache',
                    'coverage',
                    'node_modules',
                    'storage',
                    'vendor',
                ], true);
            },
        ),
    );

    $files = [];

    foreach ($iterator as $file) {
        if (! $file instanceof SplFileInfo || ! $file->isFile()) {
            continue;
        }

        $extension = $file->getExtension();
        $path = $file->getPathname();

        if ($extension === 'php' || str_ends_with($path, '.blade.php')) {
            $files[] = $path;
        }
    }

    sort($files);

    return $files;
}

function relativePath(string $basePath, string $path): string
{
    $realPath = realpath($path) ?: $path;
    $basePath = rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

    return str_starts_with($realPath, $basePath)
        ? substr($realPath, strlen($basePath))
        : $path;
}

function packageNamespaceFromLangFile(string $file): ?string
{
    if (! preg_match('#(^|/)packages/([^/]+)/resources/lang/[^/]+/[^/]+\.php$#', $file, $matches)) {
        return null;
    }

    return 'capell-' . str_replace('_', '-', $matches[2]);
}

function groupFromLangFile(string $file): string
{
    return pathinfo($file, PATHINFO_FILENAME);
}

/**
 * @param array<mixed> $values
 * @return array<string, string>
 */
function flattenTranslations(array $values, string $prefix = ''): array
{
    $translations = [];

    foreach ($values as $key => $value) {
        $stringKey = (string) $key;
        $fullKey = $prefix === '' ? $stringKey : "{$prefix}.{$stringKey}";

        if (is_array($value)) {
            $translations += flattenTranslations($value, $fullKey);

            continue;
        }

        $translations[$fullKey] = is_scalar($value) || $value === null
            ? (string) $value
            : get_debug_type($value);
    }

    return $translations;
}

/**
 * @return array<string, array{file: string, value: string}>
 */
function definedTranslations(string $basePath, array $rootPaths): array
{
    $definitions = [];

    foreach ($rootPaths as $rootPath) {
        foreach (auditFiles($rootPath) as $file) {
            if (! str_contains($file, '/resources/lang/') || ! str_ends_with($file, '.php')) {
                continue;
            }

            $namespace = packageNamespaceFromLangFile(relativePath($basePath, $file));

            if ($namespace === null) {
                continue;
            }

            $values = require $file;

            if (! is_array($values)) {
                continue;
            }

            $group = groupFromLangFile($file);

            foreach (flattenTranslations($values) as $key => $value) {
                $definitions["{$namespace}::{$group}.{$key}"] = [
                    'file' => relativePath($basePath, $file),
                    'value' => $value,
                ];
            }
        }
    }

    ksort($definitions);

    return $definitions;
}

/**
 * @return list<array{offset: int, line: int, text: string}>
 */
function dynamicTranslationCallSpans(string $contents, string $pattern): array
{
    if (preg_match_all($pattern, $contents, $matches, PREG_OFFSET_CAPTURE) === 0) {
        return [];
    }

    $spans = [];

    foreach ($matches[0] as [$matchedCall, $offset]) {
        $open = strpos($contents, '(', $offset);

        if ($open === false) {
            continue;
        }

        $depth = 0;
        $quote = null;
        $escaped = false;
        $end = strlen($contents);

        for ($cursor = $open; $cursor < strlen($contents); $cursor++) {
            $character = $contents[$cursor];

            if ($quote !== null) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($character === '\\') {
                    $escaped = true;
                } elseif ($character === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($character === "'" || $character === '"') {
                $quote = $character;
            } elseif ($character === '(') {
                $depth++;
            } elseif ($character === ')' && --$depth === 0) {
                $end = $cursor + 1;

                break;
            }
        }

        $spans[] = [
            'offset' => $offset,
            'line' => substr_count(substr($contents, 0, $offset), "\n") + 1,
            'text' => substr($contents, $offset, $end - $offset),
        ];
    }

    return $spans;
}

/**
 * @param array<string, array{file: string, value: string}> $definitions
 * @return array{static: array<string, list<string>>, dynamic: list<array{id: string, file: string, line: int, text: string, family: ?string, validated: bool}>, families: list<string>}
 */
function usedTranslations(string $basePath, array $rootPaths, array $definitions): array
{
    $static = [];
    $dynamic = [];
    $families = [];
    $callPattern = <<<'REGEX'
~(?:
    (?<![A-Za-z0-9_])__\s*\(
  | (?<![A-Za-z0-9_])trans\s*\(
  | (?<![A-Za-z0-9_])trans_choice\s*\(
  | Lang::(?:get|choice|has)\s*\(
  | @lang\s*\(
  | @choice\s*\(
)\s*(?<quote>['"])(?<key>capell-[a-z0-9-]+::[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)*)\k<quote>~x
REGEX;
    $dynamicPattern = <<<'REGEX'
~(?:
    (?<![A-Za-z0-9_])__\s*\(
  | (?<![A-Za-z0-9_])trans\s*\(
  | (?<![A-Za-z0-9_])trans_choice\s*\(
  | Lang::(?:get|choice|has)\s*\(
  | @lang\s*\(
  | @choice\s*\(
)\s*(?!['"]capell-[a-z0-9-]+::)~x
REGEX;

    foreach ($rootPaths as $rootPath) {
        foreach (auditFiles($rootPath) as $file) {
            if (str_contains($file, '/resources/lang/')) {
                continue;
            }

            $contents = file_get_contents($file);

            if ($contents === false || ! str_contains($contents, 'capell-')) {
                continue;
            }

            $relativePath = relativePath($basePath, $file);

            if (preg_match_all($callPattern, $contents, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $static[$match['key']][] = $relativePath;
                }
            }

            foreach (dynamicTranslationCallSpans($contents, $dynamicPattern) as $callSpan) {
                $expression = $callSpan['text'];

                if (str_contains($expression, 'capell-')) {
                    $family = null;
                    $validated = false;

                    if (preg_match('/[\'"](?<template>capell-[a-z0-9-]+::[A-Za-z0-9_.-]*%s[A-Za-z0-9_.-]*)[\'"]/', $expression, $familyMatch) === 1) {
                        [$prefix, $suffix] = explode('%s', $familyMatch['template'], 2);
                        $family = $prefix . '*' . $suffix;
                    } elseif (preg_match('/[\'"](?<prefix>capell-[a-z0-9-]+::[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)*\.)[\'"]\s*\./', $expression, $familyMatch) === 1) {
                        $prefix = $familyMatch['prefix'];
                        $suffix = '';
                        $family = $prefix . '*';
                    }

                    if ($family !== null) {
                        $matchingKeys = array_values(array_filter(
                            array_keys($definitions),
                            static fn (string $key): bool => str_starts_with($key, $prefix) && str_ends_with($key, $suffix),
                        ));
                        $validated = $matchingKeys !== [];

                        if ($validated) {
                            $families[$family] = true;

                            foreach ($matchingKeys as $matchingKey) {
                                $static[$matchingKey][] = $relativePath;
                            }
                        }
                    }

                    $literalKeys = [];
                    if (preg_match_all('/[\'"](?<key>capell-[a-z0-9-]+::[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)*)[\'"]/', $expression, $literalMatches) > 0) {
                        $literalKeys = array_values(array_unique($literalMatches['key']));

                        foreach ($literalKeys as $literalKey) {
                            $static[$literalKey][] = $relativePath;
                        }
                    }

                    $normalisedExpression = preg_replace('/\s+/', ' ', trim($expression)) ?? trim($expression);
                    $siteKind = $family !== null
                        ? 'family:' . $family
                        : ($literalKeys !== [] ? 'choices:' . implode('|', $literalKeys) : 'expression:' . hash('sha256', $normalisedExpression));

                    $dynamic[] = [
                        'id' => $relativePath . '::' . $siteKind,
                        'file' => $relativePath,
                        'line' => $callSpan['line'],
                        'text' => $normalisedExpression,
                        'family' => $family,
                        'validated' => $validated,
                    ];
                }
            }
        }
    }

    ksort($static);
    usort($dynamic, static fn (array $first, array $second): int => strcmp($first['id'], $second['id']));
    ksort($families);

    return [
        'static' => $static,
        'dynamic' => $dynamic,
        'families' => array_keys($families),
    ];
}

$definitions = definedTranslations($basePath, $rootPaths);
$usage = usedTranslations($basePath, $rootPaths, $definitions);
$usedKeys = array_keys($usage['static']);

$unused = array_values(array_diff(array_keys($definitions), $usedKeys));
$missing = array_values(array_diff($usedKeys, array_keys($definitions)));

sort($unused);
sort($missing);

$report = [
    'defined_count' => count($definitions),
    'used_static_count' => count($usedKeys),
    'unused_count' => count($unused),
    'missing_count' => count($missing),
    'dynamic_count' => count($usage['dynamic']),
    'dynamic_family_count' => count($usage['families']),
    'roots' => array_values($roots),
    'unused' => array_map(static fn (string $key): array => [
        'key' => $key,
        'file' => $definitions[$key]['file'] ?? null,
    ], $unused),
    'missing' => array_map(static fn (string $key): array => [
        'key' => $key,
        'used_in' => array_values(array_unique($usage['static'][$key] ?? [])),
    ], $missing),
    'dynamic' => $usage['dynamic'],
    'dynamic_families' => $usage['families'],
];

if ($format === 'json') {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} elseif ($format === 'text') {
    echo "Language key audit\n";
    echo "==================\n";
    echo 'Roots: ' . implode(', ', $report['roots']) . "\n";
    echo "Defined keys: {$report['defined_count']}\n";
    echo "Static used keys: {$report['used_static_count']}\n";
    echo "Unused keys: {$report['unused_count']}\n";
    echo "Missing keys: {$report['missing_count']}\n";
    echo "Dynamic translation expressions: {$report['dynamic_count']}\n\n";

    echo "Missing static keys\n";
    echo "-------------------\n";
    if ($report['missing'] === []) {
        echo "None\n";
    } else {
        foreach ($report['missing'] as $entry) {
            echo $entry['key'] . "\n";
            foreach ($entry['used_in'] as $file) {
                echo "  - {$file}\n";
            }
        }
    }

    echo "\nUnused defined keys\n";
    echo "-------------------\n";
    if ($report['unused'] === []) {
        echo "None\n";
    } else {
        foreach ($report['unused'] as $entry) {
            echo $entry['key'] . " ({$entry['file']})\n";
        }
    }

    echo "\nDynamic translation expressions to review\n";
    echo "-----------------------------------------\n";
    if ($report['dynamic'] === []) {
        echo "None\n";
    } else {
        foreach ($report['dynamic'] as $entry) {
            echo "{$entry['file']}:{$entry['line']} {$entry['text']}\n";
        }
    }
} else {
    fwrite(STDERR, "Unsupported format: {$format}. Use text or json.\n");
    exit(2);
}

exit($strict && ($missing !== [] || $unused !== []) ? 1 : 0);
PHP
