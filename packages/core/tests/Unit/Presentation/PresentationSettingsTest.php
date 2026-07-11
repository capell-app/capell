<?php

declare(strict_types=1);

use Capell\Core\Actions\Presentation\ResolvePresentationSettingsAction;
use Capell\Core\Data\Presentation\PresentationPresetData;
use Capell\Core\Enums\PresentationAlignment;
use Capell\Core\Enums\PresentationDeliveryMode;
use Capell\Core\Enums\PresentationLoadingStrategy;
use Capell\Core\Enums\PresentationWidthMode;
use Capell\Core\Support\Presentation\PresentationPresetRegistry;

it('normalizes missing presentation settings to safe server-rendered defaults', function (): void {
    $settings = ResolvePresentationSettingsAction::run();

    expect($settings->deliveryMode)->toBe(PresentationDeliveryMode::ServerRendered)
        ->and($settings->loadingStrategy)->toBe(PresentationLoadingStrategy::Eager)
        ->and($settings->widthMode)->toBe(PresentationWidthMode::Inherit)
        ->and($settings->alignment)->toBe(PresentationAlignment::Stretch);
});

it('resolves settings with instance overrides winning over type and preset defaults', function (): void {
    resolve(PresentationPresetRegistry::class)->register(new PresentationPresetData(
        key: 'below-fold',
        label: 'Below fold',
        icon: 'heroicon-o-arrow-down',
        description: 'Lazy visible content.',
        settings: [
            'delivery_mode' => 'lazy_fragment',
            'loading_strategy' => 'visible',
            'alignment' => 'center',
        ],
    ));

    $settings = ResolvePresentationSettingsAction::run(
        instanceSettings: [
            'presentation_preset' => 'below-fold',
            'alignment' => 'right',
            'width_mode' => 'custom',
            'custom_width' => '72rem',
        ],
        typeDefaults: [
            'alignment' => 'left',
        ],
    );

    expect($settings->deliveryMode)->toBe(PresentationDeliveryMode::LazyFragment)
        ->and($settings->loadingStrategy)->toBe(PresentationLoadingStrategy::Visible)
        ->and($settings->alignment)->toBe(PresentationAlignment::Right)
        ->and($settings->widthMode)->toBe(PresentationWidthMode::Custom)
        ->and($settings->customWidth)->toBe('72rem');
});

it('falls back to inherit when custom width is unsafe', function (): void {
    $settings = ResolvePresentationSettingsAction::run([
        'width_mode' => 'custom',
        'custom_width' => 'url(javascript:alert(1))',
    ]);

    expect($settings->widthMode)->toBe(PresentationWidthMode::Inherit)
        ->and($settings->customWidth)->toBeNull();
});

it('normalizes safe custom width functions for public presentation', function (): void {
    $settings = ResolvePresentationSettingsAction::run([
        'width_mode' => 'custom',
        'custom_width' => 'min(90vw, 72rem)',
    ]);

    expect($settings->widthMode)->toBe(PresentationWidthMode::Custom)
        ->and($settings->customWidth)->toBe('min(90vw, 72rem)')
        ->and($settings->publicCustomWidth())->toBe('min(90vw, 72rem)')
        ->and($settings->usesCustomWidth())->toBeTrue();
});

it('registers presentation presets by stable key', function (): void {
    $registry = new PresentationPresetRegistry;

    $registry->register(new PresentationPresetData(
        key: 'feature-band',
        label: 'Feature band',
        icon: 'heroicon-o-sparkles',
        description: 'Wide feature presentation.',
        settings: ['alignment' => 'center'],
    ));

    expect($registry->get('feature-band')?->label)->toBe('Feature band')
        ->and($registry->all())->toHaveKey('feature-band');
});
