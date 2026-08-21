<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

it('pins the dependency-gated next-major decision without changing the 1.x line', function (): void {
    $root = dirname(__DIR__, 4);
    $decision = json_decode(File::get($root . '/docs/packages/core-next-major-compatibility-decision.json'), true, flags: JSON_THROW_ON_ERROR);
    $baseline = json_decode(File::get($root . '/docs/packages/core-next-major-compatibility-baseline.json'), true, flags: JSON_THROW_ON_ERROR);
    $composer = json_decode(File::get($root . '/packages/core/composer.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($decision['status'])->toBe('preparation-only')
        ->and($decision['gate'])->toContain('CAP-0270')
        ->and($baseline['status'])->toBe('draft')
        ->and($composer['require']['filament/support'] ?? null)->toBe('^5.7.6');
});

it('keeps the future old-package failure wording as a consumer fixture', function (): void {
    $root = dirname(__DIR__, 4);
    $message = File::get($root . '/docs/packages/fixtures/core-2x-old-package-failure.txt');

    expect($message)->toContain('Capell 2.x')
        ->and($message)->toContain('Admin-owned media and presentation contracts')
        ->and($message)->toContain('retry discovery');
});
