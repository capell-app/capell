<?php

declare(strict_types=1);

it('runs the full real-database matrix with Testbench worker isolation', function (): void {
    $root = dirname(__DIR__, 2);
    $composer = json_decode(
        (string) file_get_contents($root . '/composer.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $workflow = (string) file_get_contents($root . '/.github/workflows/test-full.yml');

    expect($composer['scripts']['test:database:ci'] ?? null)
        ->toBe('@php -d memory_limit=1G -d max_execution_time=0 vendor/bin/testbench package:test --parallel --recreate-databases --compact --configuration=phpunit.xml --ansi')
        ->and($workflow)
        ->toContain('composer run test:database:ci')
        ->toContain('SET GLOBAL innodb_redo_log_capacity = 2147483648')
        ->toContain('SET GLOBAL innodb_flush_log_at_trx_commit = 2')
        ->toContain('SET GLOBAL sync_binlog = 0')
        ->not->toContain('composer run test:all:ci 2>&1 | tee pest-output.txt');
});
