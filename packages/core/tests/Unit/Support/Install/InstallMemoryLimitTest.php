<?php

declare(strict_types=1);

use Capell\Core\Support\Install\InstallMemoryLimit;

it('parses php memory limit quantities', function (string $configuredLimit, int $expectedBytes): void {
    expect((new InstallMemoryLimit)->bytes($configuredLimit))->toBe($expectedBytes);
})->with([
    'bytes' => ['536870912', 536_870_912],
    'kilobytes' => ['524288K', 536_870_912],
    'megabytes' => ['512M', 536_870_912],
    'gigabytes' => ['1G', 1_073_741_824],
]);

it('accepts the minimum memory limit and unlimited memory', function (string $configuredLimit): void {
    expect((new InstallMemoryLimit)->isSatisfied($configuredLimit))->toBeTrue();
})->with(['512M', '1G', '-1']);

it('rejects memory limits below the installation floor', function (string $configuredLimit): void {
    expect((new InstallMemoryLimit)->isSatisfied($configuredLimit))->toBeFalse();
})->with(['128M', '511M', '536870911']);

it('provides a stable searchable failure message', function (): void {
    expect((new InstallMemoryLimit)->failureMessage('128M'))
        ->toBe('Capell installation requires PHP memory_limit of at least 512M; the current limit is 128M.');
});

it('raises a lower effective cli memory limit to the installation floor', function (): void {
    $previousLimit = ini_get('memory_limit');
    ini_set('memory_limit', '128M');

    try {
        $memoryLimit = new InstallMemoryLimit;
        $memoryLimit->ensureMinimum();

        expect($memoryLimit->current())->toBe('512M');
    } finally {
        if (is_string($previousLimit)) {
            ini_set('memory_limit', $previousLimit);
        }
    }
});
