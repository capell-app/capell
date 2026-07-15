<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('keeps generated extension surface artifacts deterministic', function (): void {
    $root = dirname(__DIR__, 2);
    $process = new Process([PHP_BINARY, 'scripts/build-extension-surface-catalog.php', '--check'], $root);
    $process->run();

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toContain('catalogue is current');
});

it('requires direct contract IDs for every stable surface', function (): void {
    $catalog = json_decode(
        (string) file_get_contents(dirname(__DIR__, 2) . '/docs/packages/extension-surface-catalog.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($catalog['schemaVersion'])->toBe(1)
        ->and($catalog['surfaces'])->not->toBeEmpty();

    foreach ($catalog['surfaces'] as $surface) {
        expect($surface['id'])->toMatch('/^[a-z0-9]+(?:[.-][a-z0-9]+)*$/');

        if ($surface['stability'] === 'stable') {
            expect($surface['contractTestId'])->not->toBeNull();
        }
    }
});

it('links the human API references to the machine-owned catalogue', function (): void {
    $root = dirname(__DIR__, 2);

    foreach ([
        'docs/packages/extension-point-api-reference.md',
        'docs/packages/extension-surface-vocabulary.md',
    ] as $path) {
        expect((string) file_get_contents($root . '/' . $path))
            ->toContain('(extension-surface-catalog.md)');
    }
});
