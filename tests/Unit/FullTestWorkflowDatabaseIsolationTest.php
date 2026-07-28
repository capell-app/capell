<?php

declare(strict_types=1);

it('gates the exact PR and dispatch topology before splitting behaviour and package unit tests', function (): void {
    $root = dirname(__DIR__, 2);
    $composer = json_decode(
        (string) file_get_contents($root . '/composer.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $workflow = (string) file_get_contents($root . '/.github/workflows/test-full.yml');

    expect($composer['scripts']['test:database:ci'] ?? null)
        ->toContain('--log-junit=${PEST_JUNIT_LOG:?PEST_JUNIT_LOG must be set}')
        ->toContain("--passthru-php='-d memory_limit=1G'")
        ->and($composer['scripts']['test:database:package:ci'] ?? null)
        ->toContain('--testsuite=Unit --group=${PEST_TEST_GROUP:?PEST_TEST_GROUP must be set}')
        ->toContain("--passthru-php='-d memory_limit=1G'")
        ->toContain('--do-not-fail-on-empty-test-suite')
        ->and($workflow)
        ->toContain('pull_request:')
        ->toContain('workflow_dispatch:')
        ->toContain('uses: ./.github/workflows/test-fast-pr.yml')
        ->toContain('name: Sentinel - portability, cache, migrations, destructive schema')
        ->toContain('timeout-minutes: 5')
        ->toContain('needs: sentinel')
        ->toContain('composer run test:sentinel:unit:ci')
        ->toContain('composer run test:sentinel:database:ci')
        ->toContain('composer run test:database:package:ci')
        ->toContain('PAO_DISABLE: 1')
        ->toContain('package: Core')
        ->toContain('package: Admin')
        ->toContain('package: Frontend')
        ->toContain('package: Installer')
        ->toContain('package: Marketplace')
        ->toContain('Upload JUnit results')
        ->toContain('count($files) !== 7')
        ->toContain('SET GLOBAL innodb_redo_log_capacity = 2147483648')
        ->toContain('SET GLOBAL innodb_flush_log_at_trx_commit = 2')
        ->toContain('SET GLOBAL sync_binlog = 0')
        ->not->toContain('on:' . PHP_EOL . '  push:' . PHP_EOL . '    branches:' . PHP_EOL . '      - main' . PHP_EOL . PHP_EOL . 'concurrency:');
});
