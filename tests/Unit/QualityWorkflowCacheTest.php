<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

it('restores the Rector cache before running transformations', function (): void {
    $workflow = Yaml::parseFile(dirname(__DIR__, 2) . '/.github/workflows/code-quality-and-styling.yml');
    $steps = $workflow['jobs']['quality']['steps'];
    $names = array_column($steps, 'name');
    $cacheIndex = array_search('Cache Rector result cache', $names, true);
    $checkIndex = array_search('Apply Rector transformations', $names, true);

    expect($cacheIndex)->not->toBeFalse()
        ->and($checkIndex)->not->toBeFalse();

    $cache = $steps[$cacheIndex];
    $phpstan = $steps[array_search('Cache PHPStan result cache', $names, true)];
    $prefix = 'rector-${{ runner.os }}-';
    $hash = "\${{ hashFiles('composer.lock', 'rector.php', 'packages/*/rector.php') }}";

    expect($cacheIndex)->toBeLessThan($checkIndex)
        ->and($cache['uses'])->toBe($phpstan['uses'])
        ->and($cache['with']['path'])->toBe('var/rector')
        ->and($cache['with']['key'])->toBe($prefix . $hash . '-${{ github.sha }}')
        ->and(explode("\n", trim($cache['with']['restore-keys'])))->toBe([
            $prefix . $hash . '-',
            $prefix,
        ])
        ->and($cache)->not->toHaveKey('if')
        ->and($steps[$checkIndex])->not->toHaveKeys(['if', 'continue-on-error']);
});

it('retains the full quality commands and Rector change detection', function (): void {
    $workflow = Yaml::parseFile(dirname(__DIR__, 2) . '/.github/workflows/code-quality-and-styling.yml');
    $steps = $workflow['jobs']['quality']['steps'];
    $names = array_column($steps, 'name');
    $rectorIndex = array_search('Apply Rector transformations', $names, true);
    $pintIndex = array_search('Run Pint check', $names, true);

    expect($steps[$rectorIndex]['run'])->toBe('composer rector')
        ->and($steps[$rectorIndex + 1])->toBe([
            'name' => 'Verify Rector left no uncommitted changes',
            'run' => 'git diff --exit-code',
        ])
        ->and($steps[$pintIndex]['run'])->toBe('composer cs:check');
});
