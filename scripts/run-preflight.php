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
 *
 * A whole-tree run takes weight 3 (three of the five default slots) in the host-wide heavy pool,
 * so two runs from different worktrees queue instead of doubling the parallel
 * Pest/Rector/PHPStan workers. The script re-executes itself under the guard
 * once (CAPELL_PREFLIGHT_ADMITTED marks the admitted process); stages inherit
 * the slot through HEAVY_SLOT_OWNER. See scripts/heavy-admission.php.
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
    'test-weak-assertions' => 'check:test-weak-assertions',
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
    'test-weak-assertions' => 'check:test-weak-assertions',
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
 * `npm run screenshots:restore` puts the pristine copy back; reinstalling the
 * package also restores a pristine skeleton and removes the marker.
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

            npm run screenshots:restore

        If that reports no saved copy, reinstall the package instead:

            composer reinstall orchestra/testbench-core --no-scripts

        MESSAGE);

    exit(2);
}

if (getenv('CAPELL_PREFLIGHT_ADMITTED') !== '1') {
    require_once __DIR__ . '/heavy-admission.php';

    $admitted = capellHeavyCommand([PHP_BINARY, __FILE__, ...array_slice($argv, 1)], 3, 'composer-preflight');

    if ($admitted[0] !== PHP_BINARY) {
        putenv('CAPELL_PREFLIGHT_ADMITTED=1');
        putenv('CAPELL_HEAVY_ADMITTED=1');
        $process = proc_open($admitted, [STDIN, STDOUT, STDERR], $pipes);
        exit(is_resource($process) ? proc_close($process) : 127);
    }
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

/**
 * Run one stage and return its exit code and wall time. With an output file the stage's streams go there
 * instead of the terminal, so a stage running beside another can be printed whole afterwards.
 *
 * @return array{exitCode: int, seconds: float}
 */
$runStage = static function (string $name, string $script, ?string $outputFile = null) use ($composer): array {
    $stageStartedAt = hrtime(true);
    $descriptors = $outputFile === null
        ? [STDIN, STDOUT, STDERR]
        : [STDIN, ['file', $outputFile, 'w'], ['file', $outputFile, 'a']];
    $process = proc_open([$composer, $script], $descriptors, $pipes);

    if (! is_resource($process)) {
        $exitCode = 127;
        fwrite(STDERR, "Unable to start Composer.\n");
    } else {
        $exitCode = proc_close($process);
    }

    return [
        'exitCode' => $exitCode,
        'seconds' => (hrtime(true) - $stageStartedAt) / 1_000_000_000,
    ];
};

$banner = static fn (string $name, string $script): string => sprintf(
    "\n%s\n▶ %s (%s)\n%s\n",
    str_repeat('═', 64),
    $name,
    $script,
    str_repeat('═', 64),
);

/**
 * The read-only static stages of a quick run never write the tree the tests read, so they run beside the
 * tests instead of before them. The full run rewrites files (Rector, Pint) and --fail-fast must stop at
 * the first failure, so both stay strictly sequential, as does CAPELL_PREFLIGHT_SERIAL=1.
 *
 * @var list<string> $overlappedStatic
 */
$overlappedStatic = array_values(array_intersect(['phpstan', 'rector', 'pint', 'prettier', 'eslint'], array_keys($stages)));
$overlap = ! $all && ! $failFast && isset($stages['tests']) && $overlappedStatic !== []
    && getenv('CAPELL_PREFLIGHT_SERIAL') !== '1';
$sequential = $overlap ? array_diff_key($stages, array_flip([...$overlappedStatic, 'tests'])) : $stages;

foreach ($sequential as $name => $script) {
    fwrite(STDOUT, $banner($name, $script));
    $results[$name] = $runStage($name, $script);

    if ($failFast && $results[$name]['exitCode'] !== 0) {
        fwrite(STDERR, sprintf("\nPreflight stopped after failed stage: %s\n", $name));

        break;
    }
}

if ($overlap) {
    fwrite(STDOUT, sprintf(
        "\n%s\nRunning tests beside %s (CAPELL_PREFLIGHT_SERIAL=1 runs them one after another)\n%s\n",
        str_repeat('═', 64),
        implode(', ', $overlappedStatic),
        str_repeat('═', 64),
    ));

    $testsOutput = tempnam(sys_get_temp_dir(), 'capell-preflight-tests-');
    $testsStartedAt = hrtime(true);
    $testsProcess = proc_open(
        [$composer, $stages['tests']],
        [STDIN, ['file', $testsOutput, 'w'], ['file', $testsOutput, 'a']],
        $testPipes,
    );

    foreach ($overlappedStatic as $name) {
        fwrite(STDOUT, $banner($name, $stages[$name]));
        $results[$name] = $runStage($name, $stages[$name]);
    }

    $testsExitCode = is_resource($testsProcess) ? proc_close($testsProcess) : 127;
    fwrite(STDOUT, $banner('tests', $stages['tests']));
    fwrite(STDOUT, (string) file_get_contents($testsOutput));
    unlink($testsOutput);
    $results['tests'] = [
        'exitCode' => $testsExitCode,
        'seconds' => (hrtime(true) - $testsStartedAt) / 1_000_000_000,
    ];

    // Report in the declared stage order whatever order the stages finished in.
    $results = array_replace(array_intersect_key($stages, $results), $results);
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
