<?php

declare(strict_types=1);

use Capell\Core\Support\Assets\VendorAssetConditionRegistry;

it('passes unconditional vendor assets', function (): void {
    $registry = new VendorAssetConditionRegistry;

    expect($registry->passes(null))->toBeTrue()
        ->and($registry->passes(''))->toBeTrue();
});

it('evaluates registered vendor asset conditions', function (): void {
    $registry = new VendorAssetConditionRegistry;

    $registry->register('frontend-runtime', fn (): bool => true);
    $registry->register('empty-layout', fn (): bool => false);

    expect($registry->passes('frontend-runtime'))->toBeTrue()
        ->and($registry->passes('empty-layout'))->toBeFalse();
});

it('passes context arguments to registered vendor asset conditions', function (): void {
    $registry = new VendorAssetConditionRegistry;

    $registry->register('matches-runtime', fn (object $context): bool => data_get($context, 'runtime') === 'livewire');

    expect($registry->passes('matches-runtime', (object) ['runtime' => 'livewire']))->toBeTrue()
        ->and($registry->passes('matches-runtime', (object) ['runtime' => 'blade']))->toBeFalse();
});

it('does not pass unknown conditional vendor assets', function (): void {
    $registry = new VendorAssetConditionRegistry;

    expect($registry->passes('missing-condition'))->toBeFalse();
});
