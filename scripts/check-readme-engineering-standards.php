<?php

declare(strict_types=1);

$root = getenv('CAPELL_README_ENGINEERING_STANDARDS_ROOT') ?: dirname(__DIR__);

$contracts = [
    'README declares a live test matrix badge' => ['README.md', 'test-full.yml?branch=main&style=flat-square&label=test%20matrix'],
    'README declares a live quality gates badge' => ['README.md', 'code-quality-and-styling.yml?branch=main&style=flat-square&label=quality%20gates'],
    'README declares live coverage' => ['README.md', 'img.shields.io/codecov/c/github/capell-app/capell'],
    'README declares PHPStan level 8' => ['README.md', 'PHPStan-level%208'],
    'README declares 98.9% typed parameters' => ['README.md', 'parameters%20typed-98.9%25'],
    'README declares audited dependencies' => ['README.md', 'dependencies-audited'],
    'PHPStan is configured at level 8' => ['phpstan/common.neon', 'level: 8'],
    'PHPStan enforces 98.9% parameter types' => ['phpstan/common.neon', 'param_type: 98.9'],
    'quality workflow validates README standards' => ['.github/workflows/code-quality-and-styling.yml', 'composer run check:readme-engineering-standards'],
    'quality workflow runs on main pull requests' => ['.github/workflows/code-quality-and-styling.yml', "pull_request:\n    branches:\n      - main"],
    'quality workflow runs PHPStan' => ['.github/workflows/code-quality-and-styling.yml', 'composer phpstan'],
    'quality workflow audits locked dependencies' => ['.github/workflows/code-quality-and-styling.yml', 'composer audit --locked'],
    'full test workflow covers Laravel 12' => ['.github/workflows/test-full.yml', 'laravel: 12.*'],
    'full test workflow covers Laravel 13' => ['.github/workflows/test-full.yml', 'laravel: 13.*'],
    'coverage workflow enforces 90% coverage' => ['.github/workflows/coverage-release.yml', '--coverage --min=90'],
];

/** @var array<string, string> $contents */
$contents = [];
$failures = [];

foreach ($contracts as $description => [$relativePath, $expected]) {
    if (! array_key_exists($relativePath, $contents)) {
        $path = $root . '/' . $relativePath;
        $fileContents = file_get_contents($path);

        if ($fileContents === false) {
            $failures[] = "{$relativePath} could not be read.";

            continue;
        }

        $contents[$relativePath] = $fileContents;
    }

    if (! str_contains($contents[$relativePath], $expected)) {
        $failures[] = "{$description} ({$relativePath}).";
    }
}

if ($failures !== []) {
    fwrite(STDERR, "README engineering standards are out of sync:\n");

    foreach ($failures as $failure) {
        fwrite(STDERR, "- {$failure}\n");
    }

    exit(1);
}

fwrite(STDOUT, "README engineering standards are verified.\n");
