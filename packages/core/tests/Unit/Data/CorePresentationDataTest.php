<?php

declare(strict_types=1);

use Capell\Core\Data\DeletionImpactData;
use Capell\Core\Data\Presentation\PresentationSettingsData;
use Capell\Core\Enums\PackageCapability;
use Capell\Core\Enums\PresentationAlignment;
use Capell\Core\Enums\PresentationConnectionRequirement;
use Capell\Core\Enums\PresentationDeliveryMode;
use Capell\Core\Enums\PresentationDeviceVisibility;
use Capell\Core\Enums\PresentationLoadingStrategy;
use Capell\Core\Enums\PresentationWidthMode;
use Capell\Core\ThemeStudio\Data\BrandProfileData;

it('combines deletion impact counts across site deletion planning boundaries', function (): void {
    $impact = new DeletionImpactData(pages: 2, siteDomains: 1, pageUrls: 3)
        ->add(new DeletionImpactData(layouts: 2, translations: 4));

    expect($impact->pages)->toBe(2)
        ->and($impact->siteDomains)->toBe(1)
        ->and($impact->layouts)->toBe(2)
        ->and($impact->pageUrls)->toBe(3)
        ->and($impact->translations)->toBe(4)
        ->and($impact->total())->toBe(12);
});

it('exposes public presentation settings without leaking author-only width presets', function (): void {
    $settings = new PresentationSettingsData(
        deliveryMode: PresentationDeliveryMode::LazyFragment,
        deviceVisibility: PresentationDeviceVisibility::DesktopOnly,
        connectionRequirement: PresentationConnectionRequirement::FastOnly,
        loadingStrategy: PresentationLoadingStrategy::Visible,
        widthMode: PresentationWidthMode::Custom,
        alignment: PresentationAlignment::Center,
        presentationPreset: 'editor-only-preset',
        minViewportWidth: 768,
        maxViewportWidth: 1440,
        customWidth: 'min(90vw, 72rem)',
    );

    expect($settings->publicRuntimePayload())->toBe([
        'delivery_mode' => 'lazy_fragment',
        'device_visibility' => 'desktop_only',
        'connection_requirement' => 'fast_only',
        'loading_strategy' => 'visible',
        'width_mode' => 'custom',
        'alignment' => 'center',
        'min_viewport_width' => 768,
        'max_viewport_width' => 1440,
    ])->and($settings->publicCustomWidth())->toBe('min(90vw, 72rem)')
        ->and($settings->usesCustomWidth())->toBeTrue();
});

it('does not expose unsafe direct custom width data to public presentation', function (): void {
    $settings = new PresentationSettingsData(
        widthMode: PresentationWidthMode::Custom,
        customWidth: '1px; background-image: url(https://example.test/track)',
    );

    expect($settings->publicCustomWidth())->toBeNull()
        ->and($settings->usesCustomWidth())->toBeFalse();
});

it('normalizes theme brand overrides into runtime CSS tokens', function (): void {
    $brand = (new BrandProfileData)
        ->merge([
            'primaryColor' => PackageCapability::PublicForm,
            'accentColor' => ['not scalar'],
            'headingFont' => 'playfair',
            'bodyFont' => 'sora',
            'radius' => 'none',
            'headingScale' => 'compact',
            'cardDensity' => 'spacious',
            'overlayTreatment' => 'none',
        ]);

    $tokens = $brand->tokens();

    expect($brand->primaryColor)->toBe('public-form')
        ->and($brand->accentColor)->toBe('#92400e')
        ->and($tokens['--theme-heading-font'])->toBe("'Playfair Display', Georgia, serif")
        ->and($tokens['--theme-body-font'])->toBe("'Sora', 'Inter', system-ui, sans-serif")
        ->and($tokens['--theme-radius-value'])->toBe('0')
        ->and($tokens['--theme-heading-scale-ratio'])->toBe('1.125')
        ->and($tokens['--theme-card-density-gap'])->toBe('1.5rem')
        ->and($tokens['--theme-overlay-opacity'])->toBe('0');
});
