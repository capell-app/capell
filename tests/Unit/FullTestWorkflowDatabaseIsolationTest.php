<?php

declare(strict_types=1);
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

require_once __DIR__ . '/../Support/DomQuery.php';

it('configures isolated matrix jobs and retains portable runner safety flags', function (): void {
    $root = dirname(__DIR__, 2);
    $workflow = Yaml::parseFile($root . '/.github/workflows/test-full.yml');
    $composer = json_decode((string) file_get_contents($root . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    $configuration = new DOMDocument;
    $configuration->load($root . '/phpunit.xml');
    expect($workflow['on'])->toHaveKeys(['pull_request', 'workflow_dispatch'])
        ->and($workflow['env']['CACHE_STORE'])->toBe('array')
        ->and($workflow['jobs']['fast-pr']['uses'])->toBe('./.github/workflows/test-fast-pr.yml')
        ->and(domElement(new DOMXPath($configuration), '/phpunit/php/ini[@name="memory_limit"]')->getAttribute('value'))->toBe('2G');
    foreach (['behaviour', 'unit', 'portability'] as $kind) {
        $job = $workflow['jobs']['test-' . $kind];
        expect($job['strategy']['fail-fast'])->toBeFalse()
            ->and($job['strategy']['matrix'])->toBe('${{ fromJSON(needs.matrix.outputs.' . $kind . ') }}')
            ->and($job['env']['PAO_DISABLE'])->toBe(1);
        $commands = implode("\n", array_column($job['steps'], 'run'));
        // Prepared framework dependencies and generated per-cell databases prevent cross-job pollution.
        expect($commands)->toContain('scripts/prepare-test-all-dependencies.php')
            ->toContain($kind === 'portability' ? 'scripts/run-test-all-portability-cell.php' : 'scripts/run-test-all-cell.php')
            ->not->toContain('composer require --no-interaction');
    }

    foreach (['test:unit', 'test:fast', 'test:fast:ci', 'test:all', 'test:all:ci', 'test:tia', 'test:shards'] as $script) {
        // This runner does not support paratest's PHP passthrough flag.
        expect($composer['scripts'][$script])->not->toContain('--passthru-php');
    }

    // Required evidence paths and non-empty portability suites are release safety contracts.
    expect($composer['scripts']['test:database:ci'])->toContain('${PEST_JUNIT_LOG:?')
        ->and($composer['scripts']['test:database:package:ci'])->toContain('${PEST_TEST_SUITE:?', '${PEST_TEST_GROUP:?')
        ->and($composer['scripts']['test:database:portability:ci'])->toContain('--group=database-portability', '--fail-on-empty-test-suite');
});

it('requires the exact metric identities emitted by the current matrix', function (): void {
    require_once dirname(__DIR__, 2) . '/scripts/test-all/TestAllMatrix.php';
    $workflow = Yaml::parseFile(dirname(__DIR__, 2) . '/.github/workflows/test-full.yml');
    $steps = array_column($workflow['jobs']['engineering-metrics']['steps'], null, 'id');
    $script = $steps['engineering-metrics']['run'];
    $directory = sys_get_temp_dir() . '/capell-metric-identities-' . bin2hex(random_bytes(8));
    mkdir($directory . '/engineering-metrics', 0777, true);
    file_put_contents($directory . '/aggregate.sh', $script);
    $behaviour = TestAllMatrix::behaviour();
    $unit = TestAllMatrix::unit();
    $files = [];
    foreach ([...$behaviour, ...$unit] as $cell) {
        if ($cell['laravel'] !== '13.*') {
            continue;
        }

        $suite = $cell['test_suite_slug'] ?? strtolower($cell['test_suite']);
        $package = $cell['package_slug'] ?? $cell['package'];
        $file = $directory . sprintf('/engineering-metrics/engineering-metrics-%s-%s.json', $suite, $package);
        file_put_contents($file, json_encode(['tests' => 1, 'assertions' => 2, 'phpstan_level' => 10], JSON_THROW_ON_ERROR));
        $files[] = $file;
    }

    try {
        $process = new Process(['bash', 'aggregate.sh'], $directory, [
            'BEHAVIOUR_MATRIX' => json_encode(['include' => $behaviour], JSON_THROW_ON_ERROR),
            'UNIT_MATRIX' => json_encode(['include' => $unit], JSON_THROW_ON_ERROR),
            'GITHUB_OUTPUT' => $directory . '/output',
            'PATH' => dirname(PHP_BINARY) . PATH_SEPARATOR . getenv('PATH'),
        ]);
        expect($process->run())->toBe(0)
            ->and(file_get_contents($directory . '/output'))->toContain('tests=' . count($files));
        // Preserve the count: the old count-only guard accepted this wrong cell.
        rename($files[0], $directory . '/engineering-metrics/unexpected.json');
        expect($process->run())->not->toBe(0)
            ->and($process->getErrorOutput())->toContain(basename($files[0]), 'unexpected.json');
    } finally {
        foreach (glob($directory . '/engineering-metrics/*') ?: [] as $file) {
            unlink($file);
        }

        foreach (glob($directory . '/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        rmdir($directory . '/engineering-metrics');
        rmdir($directory);
    }
});
