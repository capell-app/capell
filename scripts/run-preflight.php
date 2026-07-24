<?php

declare(strict_types=1);

/**
 * Run independent preflight gates without losing later diagnostics when one
 * gate fails. Stage commands remain Composer scripts so there is one source of
 * truth for local and CI behaviour.
 */
$all = in_array('--all', $argv, true);
$requested = array_values(array_filter(
    array_slice($argv, 1),
    static fn (string $argument): bool => $argument !== '--all',
));

/** @var array<string, string> $quickStages */
$quickStages = [
    'phpstan' => 'analyze',
    'rector' => 'rector:all:check',
    'pint' => 'cs:check',
    'prettier' => 'prettier:check',
    'eslint' => 'eslint',
    'tests' => 'test:preflight',
];

/** @var array<string, string> $fullStages */
$fullStages = [
    'composer-paths' => 'check:composer-paths',
    'support-contract' => 'check:support-contract',
    'docs-links' => 'check:docs-links',
    'root-docs' => 'check:root-docs',
    'docs-orphans' => 'check:docs-orphans',
    'docs-requirements' => 'check:docs-requirements',
    'docs-env' => 'check:docs-env',
    'docs-config' => 'check:docs-config',
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
    'pest-shards' => 'check:pest-shards',
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
