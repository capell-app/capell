<?php

declare(strict_types=1);

it('lets the coverage workflow control its PHP memory limit', function (): void {
    $root = dirname(__DIR__, 2);
    $workflow = file_get_contents($root . '/.github/workflows/coverage-release.yml');
    $phpunitConfiguration = file_get_contents($root . '/phpunit.xml');

    expect($workflow)
        ->toContain('php -d memory_limit=-1')
        ->and($phpunitConfiguration)
        ->not->toContain('<ini name="memory_limit"');
});
