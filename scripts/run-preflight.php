<?php

declare(strict_types=1);

/**
 * Run independent preflight gates without losing later diagnostics when one
 * gate fails. Stage commands remain Composer scripts so there is one source of
 * truth for local and CI behaviour.
 *
 * Pass --fail-fast for focused local iteration when later stages would only
 * repeat work after the first actionable failure. The default remains the
 * complete diagnostic pass used by the broad preflight gate.
 */
$all = in_array('--all', $argv, true);
$failFast = in_array('--fail-fast', $argv, true);
$requested = array_values(array_filter(
    array_slice($argv, 1),
    static fn (string $argument): bool => ! in_array($argument, ['--all', '--fail-fast'], true),
));

/** @var array<string, string> $quickStages */
$quickStages = [
    'composer-majors' => 'check:composer-majors',
    'queue-contract' => 'check:queue-contract',
    'language-keys' => 'check:lang-keys',
    'docs-spelling' => 'check:docs-spelling',
    'package-dependencies' => 'check:package-dependencies',
    'split-repository-health' => 'check:split-repository-health',
    'phpstan' => 'analyze',
    'rector' => 'rector:all:check',
    'pint' => 'cs:check',
    'prettier' => 'prettier:check',
    'eslint' => 'eslint',
    'agent-schema' => 'check:agent-schema',
    'tests' => 'test:preflight',
];

/** @var array<string, string> $fullStages */
$fullStages = [
    'composer-paths' => 'check:composer-paths',
    'composer-majors' => 'check:composer-majors',
    'package-dependencies' => 'check:package-dependencies',
    'split-repository-health' => 'check:split-repository-health',
    'support-contract' => 'check:support-contract',
    'queue-contract' => 'check:queue-contract',
    'language-keys' => 'check:lang-keys',
    'docs-spelling' => 'check:docs-spelling',
    'docs-links' => 'check:docs-links',
    'root-docs' => 'check:root-docs',
    'readme-engineering-standards' => 'check:readme-engineering-standards',
    'docs-orphans' => 'check:docs-orphans',
    'docs-requirements' => 'check:docs-requirements',
    'docs-commands' => 'check:docs-commands',
    'docs-env' => 'check:docs-env',
    'docs-config' => 'check:docs-config',
    'agent-schema' => 'check:agent-schema',
    'docs-screenshots' => 'check:docs-screenshots',
    'extension-surfaces' => 'check:extension-surfaces',
    'stable-extension-api' => 'check:stable-extension-api',
    'composer-lock' => 'check:composer-lock',
    'rector' => 'rector:all',
    'pint' => 'cs:fix',
    'prettier' => 'prettier:check',
    'eslint' => 'eslint',
    'phpstan' => 'analyze',
    'phpstan-baseline' => 'phpstan:baseline-check',
    'security-audit' => 'security:audit',
    'tests' => 'test:preflight',
];

$stages = $all ? $fullStages : $quickStages;

if ($requested !== []) {
    $unknown = array_values(array_diff($requested, array_keys($stages)));

    if ($unknown !== []) {
        fwrite(STDERR, sprintf(
            "Unknown preflight stage(s): %s\nAvailable stages: %s\n",
            implode(', ', $unknown),
            implode(', ', array_keys($stages)),
        ));

        exit(2);
    }

    $stages = array_intersect_key($stages, array_flip($requested));
}

/**
 * Stages that shell out to npx. Without node_modules, npx silently downloads
 * whatever major version it resolves and reports failures from that version
 * instead of the pinned one, which reads as repository drift.
 *
 * @var list<string> $nodeStages
 */
$nodeStages = ['prettier', 'eslint'];
$requestedNodeStages = array_values(array_intersect($nodeStages, array_keys($stages)));

if ($requestedNodeStages !== [] && ! is_dir(dirname(__DIR__) . '/node_modules')) {
    fwrite(STDERR, sprintf(
        <<<'MESSAGE'
        node_modules/ is missing, so the %s stage(s) cannot use the pinned toolchain.

        Install the Node dependencies first:

            npm ci

        Or run only the PHP stages:

            composer preflight %s

        MESSAGE,
        implode(' and ', $requestedNodeStages),
        implode(' ', array_keys(array_diff_key($stages, array_flip($nodeStages)))),
    ));

    exit(2);
}

/**
 * scripts/screenshots/prepare-workbench.sh rewrites the shared Testbench
 * skeleton under vendor/ (providers, migrations, caches, published assets) and
 * leaves a marker there. Tests and static analysis booted from that skeleton
 * fail in ways that look like regressions, so stop before running them.
 * Reinstalling the package restores a pristine skeleton and removes the marker.
 *
 * @var list<string> $skeletonStages
 */
$skeletonStages = ['phpstan', 'tests'];
$skeletonMarker = dirname(__DIR__) . '/vendor/orchestra/testbench-core/laravel/.capell-workbench-prepared';

if (array_intersect($skeletonStages, array_keys($stages)) !== [] && is_file($skeletonMarker)) {
    fwrite(STDERR, <<<'MESSAGE'
        The shared Testbench skeleton was rewritten by scripts/screenshots/prepare-workbench.sh, so
        tests and static analysis would fail for reasons unrelated to your change.

        Restore a pristine skeleton, or run workbench and screenshot work from a separate checkout:

            composer reinstall orchestra/testbench-core --no-scripts

        MESSAGE);

    exit(2);
}

/**
 * The test stage renders frontend pages against the Testbench skeleton, which
 * needs the prebuilt capell-frontend assets published into its public/ directory.
 * A freshly installed or reinstalled skeleton has none, and the missing Vite
 * manifest then fails dozens of unrelated frontend tests. Publish them first and
 * stop with the exact fix if that does not produce the manifest.
 */
$testbench = dirname(__DIR__) . '/vendor/bin/testbench';
$frontendAssets = dirname(__DIR__) . '/vendor/orchestra/testbench-core/laravel/public/vendor/capell-frontend';

if (isset($stages['tests']) && is_file($testbench) && ! is_file($frontendAssets . '/manifest.json')) {
    fwrite(STDOUT, "Publishing the prebuilt capell-frontend assets into the Testbench skeleton.\n");

    $publish = proc_open(
        [PHP_BINARY, $testbench, 'vendor:publish', '--tag=capell-frontend-assets', '--force'],
        [STDIN, STDOUT, STDERR],
        $publishPipes,
        dirname(__DIR__),
    );

    if (is_resource($publish)) {
        proc_close($publish);
    }

    if (! is_file($frontendAssets . '/manifest.json')) {
        fwrite(STDERR, <<<'MESSAGE'
            The Testbench skeleton has no published frontend assets (public/vendor/capell-frontend/manifest.json),
            so frontend tests would fail with "Vite manifest not found".

            Publish them, then rerun preflight:

                php vendor/bin/testbench vendor:publish --tag=capell-frontend-assets --force

            MESSAGE);

        exit(2);
    }
}

$composer = getenv('COMPOSER_BINARY');
$composer = is_string($composer) && $composer !== '' ? $composer : 'composer';
$results = [];
$startedAt = hrtime(true);

foreach ($stages as $name => $script) {
    fwrite(STDOUT, sprintf("\n%s\n▶ %s (%s)\n%s\n", str_repeat('═', 64), $name, $script, str_repeat('═', 64)));

    $stageStartedAt = hrtime(true);
    $process = proc_open([$composer, $script], [STDIN, STDOUT, STDERR], $pipes);

    if (! is_resource($process)) {
        $exitCode = 127;
        fwrite(STDERR, "Unable to start Composer.\n");
    } else {
        $exitCode = proc_close($process);
    }

    $results[$name] = [
        'exitCode' => $exitCode,
        'seconds' => (hrtime(true) - $stageStartedAt) / 1_000_000_000,
    ];

    if ($failFast && $exitCode !== 0) {
        fwrite(STDERR, sprintf("\nPreflight stopped after failed stage: %s\n", $name));

        break;
    }
}

$failed = array_filter(
    $results,
    static fn (array $result): bool => $result['exitCode'] !== 0,
);

fwrite(STDOUT, sprintf("\n%s\nPreflight summary (%.1fs)\n%s\n", str_repeat('─', 64), (hrtime(true) - $startedAt) / 1_000_000_000, str_repeat('─', 64)));

foreach ($results as $name => $result) {
    fwrite(STDOUT, sprintf(
        "  %s %-24s %7.1fs%s\n",
        $result['exitCode'] === 0 ? 'PASS' : 'FAIL',
        $name,
        $result['seconds'],
        $result['exitCode'] === 0 ? '' : sprintf(' (exit %d)', $result['exitCode']),
    ));
}

if ($failed !== []) {
    fwrite(STDERR, sprintf("\nPreflight failed: %s\n", implode(', ', array_keys($failed))));
    exit(1);
}

fwrite(STDOUT, "\nPreflight passed.\n");
