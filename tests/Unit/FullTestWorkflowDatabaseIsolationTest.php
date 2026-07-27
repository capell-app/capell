<?php

declare(strict_types=1);

it('serializes the full real-database test matrix', function (): void {
    $root = dirname(__DIR__, 2);
    $composer = json_decode(
        (string) file_get_contents($root . '/composer.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $workflow = (string) file_get_contents($root . '/.github/workflows/test-full.yml');

    expect($composer['scripts']['test:database:ci'] ?? null)
        ->toBe('@php -d memory_limit=1G -d max_execution_time=0 vendor/bin/pest --colors=always --compact --configuration=phpunit.xml')
        ->and($workflow)
        ->toContain('composer run test:database:ci')
        ->not->toContain('composer run test:all:ci 2>&1 | tee pest-output.txt');
});
