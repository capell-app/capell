<?php

declare(strict_types=1);

use Capell\Core\Enums\PresentationLoadingStrategy;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Theme;
use Capell\Frontend\Data\FrontendAssetRequirementData;
use Capell\Frontend\Support\Assets\FrontendResourceRegistry;
use Capell\Frontend\Support\Assets\ThemeResourceResolver;

it('resolves named editor theme resources into frontend resource groups', function (): void {
    $theme = new Theme;
    $theme->meta = [
        'assets_path' => 'build',
        'editor' => [
            'resources' => [
                'theme.carousel' => [
                    'assets' => [
                        'resources/css/widgets/carousel.css',
                        [
                            'source' => 'resources/js/widgets/carousel.js',
                            'loading' => PresentationLoadingStrategy::Visible->value,
                            'defer' => true,
                        ],
                    ],
                ],
            ],
        ],
    ];

    $group = (new ThemeResourceResolver)->group($theme, 'theme.carousel');

    expect($group?->key)->toBe('theme.carousel')
        ->and($group?->resources)->toHaveCount(2)
        ->and($group?->resources[0]->kind)->toBe(FrontendAssetRequirementData::KIND_CSS)
        ->and($group?->resources[0]->source)->toBe('resources/css/widgets/carousel.css')
        ->and($group?->resources[0]->buildPath)->toBe('build')
        ->and($group?->resources[1]->kind)->toBe(FrontendAssetRequirementData::KIND_JS)
        ->and($group?->resources[1]->loadingStrategy)->toBe(PresentationLoadingStrategy::Visible)
        ->and($group?->resources[1]->defer)->toBeTrue();
});

it('uses loaded theme blueprint resources as the resource group fallback', function (): void {
    $type = new Blueprint;
    $type->meta = [
        'assets_path' => 'theme-build',
        'editor' => [
            'resources' => [
                'theme.lightbox' => [
                    'assets' => [
                        [
                            'source' => 'resources/js/widgets/lightbox.js',
                            'loading_strategy' => PresentationLoadingStrategy::Interaction->value,
                        ],
                    ],
                ],
            ],
        ],
    ];
    $theme = new Theme;
    $theme->meta = [];
    $theme->setRelation('blueprint', $type);

    $group = (new ThemeResourceResolver)->group($theme, 'theme.lightbox');

    expect($group?->resources)->toHaveCount(1)
        ->and($group?->resources[0]->source)->toBe('resources/js/widgets/lightbox.js')
        ->and($group?->resources[0]->buildPath)->toBe('theme-build')
        ->and($group?->resources[0]->loadingStrategy)->toBe(PresentationLoadingStrategy::Interaction);
});

it('deduplicates repeated assets inside a theme resource group', function (): void {
    $theme = new Theme;
    $theme->meta = [
        'editor' => [
            'resources' => [
                'theme.carousel' => [
                    'assets' => [
                        'resources/js/widgets/carousel.js',
                        'resources/js/widgets/carousel.js',
                    ],
                ],
            ],
        ],
    ];

    $group = (new ThemeResourceResolver)->group($theme, 'theme.carousel');

    expect($group?->resources)->toHaveCount(1);
});

it('falls back to registered package defaults when a theme does not define a group', function (): void {
    $registry = new FrontendResourceRegistry;
    $registry->register(
        key: 'package.carousel',
        label: 'Carousel',
        assets: ['resources/css/package-carousel.css'],
        package: 'capell-app/carousel',
        defaultBuildPath: 'vendor/carousel',
    );

    $theme = new Theme;
    $theme->meta = [];

    $group = new ThemeResourceResolver($registry)->group($theme, 'package.carousel');

    expect($group?->label)->toBe('Carousel')
        ->and($group?->origin)->toBe('package')
        ->and($group?->resources[0]->source)->toBe('resources/css/package-carousel.css');
});

it('prefers theme resource metadata over package defaults', function (): void {
    $registry = new FrontendResourceRegistry;
    $registry->register(
        key: 'shared.gallery',
        label: 'Package Gallery',
        assets: ['resources/css/package-gallery.css'],
        defaultBuildPath: 'vendor/gallery',
    );

    $theme = new Theme;
    $theme->meta = [
        'editor' => [
            'resources' => [
                'shared.gallery' => [
                    'label' => 'Theme Gallery',
                    'assets' => ['resources/css/theme-gallery.css'],
                ],
            ],
        ],
    ];

    $group = new ThemeResourceResolver($registry)->group($theme, 'shared.gallery');

    expect($group?->label)->toBe('Theme Gallery')
        ->and($group?->origin)->toBe('theme')
        ->and($group?->resources[0]->source)->toBe('resources/css/theme-gallery.css');
});
