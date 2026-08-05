<?php

declare(strict_types=1);

it('keeps the PHP memory limit owned solely by the phpunit configuration', function (): void {
    // Strips comments, the memory_limit declaration and blank lines, leaving the
    // functional configuration the two phpunit files must agree on.
    $normalise = static function (string $xml): string {
        $withoutComments = (string) preg_replace('/<!--.*?-->/s', '', $xml);
        $withoutLimit = (string) preg_replace('/\s*<ini name="memory_limit"[^>]*\/>/', '', $withoutComments);

        return trim((string) preg_replace('/\n\s*\n/', "\n", $withoutLimit));
    };

    $root = dirname(__DIR__, 2);
    $mainConfiguration = (string) file_get_contents($root . '/phpunit.xml');
    $coverageConfiguration = (string) file_get_contents($root . '/phpunit-coverage.xml');
    $workflow = (string) file_get_contents($root . '/.github/workflows/coverage-release.yml');
    $profiler = (string) file_get_contents($root . '/scripts/profile-pest-tests.php');
    $composer = json_decode(
        (string) file_get_contents($root . '/composer.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    // PHPUnit applies <php><ini> through ini_set() while bootstrapping, in the
    // runner and in every parallel worker alike. That happens after PHP has read
    // the command line, so it silently overrides `-d memory_limit=` and paratest's
    // `--passthru-php`. The phpunit configuration is therefore the only place that
    // can set the limit, and any other declaration lies about the effective value.
    expect($mainConfiguration)->toContain('<ini name="memory_limit" value="1G"/>')
        ->and($coverageConfiguration)->toContain('<ini name="memory_limit" value="12G"/>');

    // The coverage variant exists only to raise that limit for the parallel
    // runner's merge step. Everything else must stay in lockstep.
    expect($normalise($coverageConfiguration))
        ->toBe($normalise($mainConfiguration));

    $offendingScripts = [];

    foreach ($composer['scripts'] as $name => $script) {
        foreach ((array) $script as $command) {
            if (! is_string($command)) {
                continue;
            }

            if (! str_contains($command, 'vendor/bin/pest')) {
                continue;
            }

            if (str_contains($command, 'memory_limit')) {
                $offendingScripts[] = $name;
            }
        }
    }

    expect($offendingScripts)->toBe([])
        ->and($workflow)->not->toContain('memory_limit')
        ->and($profiler)->not->toContain('memory_limit');
});

it('runs every coverage and mutation workload against the coverage configuration', function (): void {
    $root = dirname(__DIR__, 2);
    $workflow = (string) file_get_contents($root . '/.github/workflows/coverage-release.yml');
    $composer = json_decode(
        (string) file_get_contents($root . '/composer.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    $coverageScripts = ['coverage', 'coverage-report', 'coverage:blade', 'test:mutate', 'test:mutate:ci'];

    foreach ($coverageScripts as $name) {
        $commands = array_filter(
            (array) ($composer['scripts'][$name] ?? []),
            static fn (mixed $command): bool => is_string($command) && str_contains($command, 'vendor/bin/pest'),
        );

        expect($commands)->not->toBeEmpty();

        foreach ($commands as $command) {
            expect($command)
                ->toContain('--configuration=phpunit-coverage.xml')
                ->not->toContain('--configuration=phpunit.xml');
        }
    }

    expect($workflow)->toContain('--configuration=phpunit-coverage.xml');
});

it('pins an array cache store around the release coverage optimize step', function (): void {
    // Testbench seeds the skeleton environment from its own .env.example when
    // the workbench directory carries no environment file, and that ships
    // CACHE_STORE=database. `testbench optimize` then asks laravel-data to write
    // its cached structures into a `cache` table the in-memory sqlite connection
    // has never migrated, which took run 30982165320 down before a single test
    // ran. The process environment wins over the skeleton file, so both the
    // workflow and the scripts must set the store themselves.
    $root = dirname(__DIR__, 2);
    $workflow = (string) file_get_contents($root . '/.github/workflows/coverage-release.yml');
    $composer = json_decode(
        (string) file_get_contents($root . '/composer.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($workflow)->toContain('CACHE_STORE: array');

    foreach (['coverage', 'coverage-report'] as $name) {
        $optimizeCommands = array_filter(
            (array) ($composer['scripts'][$name] ?? []),
            static fn (mixed $command): bool => is_string($command) && str_contains($command, 'testbench optimize'),
        );

        expect($optimizeCommands)->not->toBeEmpty();

        foreach ($optimizeCommands as $command) {
            expect($command)->toStartWith('CACHE_STORE=array ');
        }
    }
});

it('runs the release coverage workload in parallel', function (): void {
    $root = dirname(__DIR__, 2);
    $workflow = (string) file_get_contents($root . '/.github/workflows/coverage-release.yml');
    $composer = json_decode(
        (string) file_get_contents($root . '/composer.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    // The workflow hand-rolls its own pest invocation so it can add --coverage-clover,
    // which means it can drift from the `coverage` script it mirrors. Serial coverage
    // took 3940s on run 30619680673 and pushed the job past an hour, so the parallel
    // flag is the part of that script the workflow must never lose.
    $workflowCoverageCommand = null;

    foreach (explode("\n", $workflow) as $line) {
        if (str_contains($line, 'vendor/bin/pest') && str_contains($line, '--coverage')) {
            $workflowCoverageCommand = $line;
        }
    }

    expect($workflowCoverageCommand)->not->toBeNull()
        ->and($workflowCoverageCommand)->toContain('--parallel');

    foreach ((array) $composer['scripts']['coverage'] as $command) {
        if (is_string($command) && str_contains($command, 'vendor/bin/pest')) {
            expect($command)->toContain('--parallel');
        }
    }
});
