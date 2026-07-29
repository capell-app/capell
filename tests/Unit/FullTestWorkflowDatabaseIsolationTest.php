<?php

declare(strict_types=1);

it('gates the exact PR and dispatch topology through repository-owned Test All scripts', function (): void {
    $root = dirname(__DIR__, 2);
    $composer = json_decode(
        (string) file_get_contents($root . '/composer.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $workflow = (string) file_get_contents($root . '/.github/workflows/test-full.yml');

    expect($composer['scripts']['test:fast:ci'] ?? null)
        ->not->toContain('--passthru-php')
        ->and($composer['scripts']['test:database:ci'] ?? null)
        ->toContain('--log-junit=${PEST_JUNIT_LOG:?PEST_JUNIT_LOG must be set}')
        ->toContain("--passthru-php='-d memory_limit=1G'")
        ->and($composer['scripts']['test:database:package:ci'] ?? null)
        ->toContain('--testsuite=${PEST_TEST_SUITE:?PEST_TEST_SUITE must be set}')
        ->toContain('--group=${PEST_TEST_GROUP:?PEST_TEST_GROUP must be set}')
        ->toContain("--passthru-php='-d memory_limit=1G'")
        ->toContain('--do-not-fail-on-empty-test-suite')
        ->and($workflow)
        ->toContain('pull_request:')
        ->toContain('workflow_dispatch:')
        ->toContain('uses: ./.github/workflows/test-fast-pr.yml')
        ->toContain('php scripts/test-all-matrix.php behaviour')
        ->toContain('php scripts/test-all-matrix.php unit')
        ->toContain('fromJSON(needs.matrix.outputs.behaviour)')
        ->toContain('fromJSON(needs.matrix.outputs.unit)')
        ->toContain('name: Sentinel - portability, cache, migrations, destructive schema')
        ->toContain('php scripts/prepare-test-all-dependencies.php')
        ->toContain('php scripts/run-test-all-cell.php --cell=sentinel-unit')
        ->toContain('php scripts/run-test-all-cell.php --cell=sentinel-database')
        ->toContain('matrix: ${{ fromJSON(needs.matrix.outputs.behaviour) }}')
        ->toContain('matrix: ${{ fromJSON(needs.matrix.outputs.unit) }}')
        ->toContain('fail-fast: false')
        ->toContain('CACHE_STORE: array')
        ->toContain('PAO_DISABLE: 1')
        ->toContain('Upload Test All evidence')
        ->toContain('count($files) !== 11')
        ->toContain('SET GLOBAL innodb_redo_log_capacity = 2147483648')
        ->toContain('SET GLOBAL innodb_flush_log_at_trx_commit = 2')
        ->toContain('SET GLOBAL sync_binlog = 0')
        ->not->toContain('composer require --no-interaction')
        ->not->toContain('matrix:' . PHP_EOL . '        include:')
        ->not->toContain('on:' . PHP_EOL . '  push:' . PHP_EOL . '    branches:' . PHP_EOL . '      - main' . PHP_EOL . PHP_EOL . 'concurrency:');
});
