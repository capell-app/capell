<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

require_once dirname(__DIR__, 2) . '/scripts/check-stable-extension-api.php';

it('keeps the pending first-public-release baseline current', function (): void {
    $root = dirname(__DIR__, 2);
    $process = new Process([PHP_BINARY, 'scripts/check-stable-extension-api.php', '--check'], $root);
    $process->run();
    $output = $process->getOutput();

    expect($process->getExitCode())->toBe(0)
        ->and(
            str_contains($output, 'baseline is current')
            || str_contains($output, 'Pending stable API drift (compatibility not active):'),
        )->toBeTrue()
        ->and(json_decode((string) file_get_contents($root . '/docs/packages/stable-extension-api-baseline.json'), true, flags: JSON_THROW_ON_ERROR)['status'])
        ->toBe('pending-first-public-release');
});

it('classifies every compatibility-relevant form of stable drift', function (): void {
    $baseline = [
        'surfaces' => [
            'stable.removed' => ['signature' => 'a'],
            'stable.changed' => ['signature' => 'before'],
        ],
        'manifestRequirements' => ['name'],
        'packageConstraints' => ['php' => '^8.4'],
        'migrations' => ['create_records.php'],
        'configKeys' => ['capell.stable'],
    ];
    $current = [
        'surfaces' => ['stable.changed' => ['signature' => 'after']],
        'manifestRequirements' => ['name', 'providers'],
        'packageConstraints' => ['php' => '^8.5'],
        'migrations' => [],
        'configKeys' => ['capell.renamed'],
    ];

    /** @phpstan-ignore-next-line function.notFound (Function is loaded from the required executable script above.) */
    expect(capellStableApiDrift($baseline, $current))->toBe([
        'removed class: stable.removed',
        'changed public signature: stable.changed',
        'manifestRequirements',
        'packageConstraints',
        'migrations',
        'configKeys',
    ]);
});
