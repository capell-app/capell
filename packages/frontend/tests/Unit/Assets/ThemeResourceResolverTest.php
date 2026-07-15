<?php

declare(strict_types=1);

use Capell\Core\Enums\PresentationLoadingStrategy;
use Capell\Core\Models\Theme;
use Capell\Frontend\Data\Assets\FrontendResourceData;
use Capell\Frontend\Data\Assets\FrontendResourceGroupData;
use Capell\Frontend\Data\Assets\PublicResourceSourceData;
use Capell\Frontend\Enums\FrontendResourceKind;
use Capell\Frontend\Support\Assets\FrontendResourceRegistry;
use Capell\Frontend\Support\Assets\ThemeResourceResolver;

it('parses only local editor theme resources into typed groups', function (): void {
    $theme = new Theme;
    $theme->meta = [
        'editor' => [
            'resources' => [
                'theme.carousel' => [
                    'label' => 'Carousel',
                    'assets' => [
                        'vendor/theme/carousel.css',
                        ['path' => 'vendor/theme/carousel.js', 'loading' => 'visible'],
                        'https://cdn.example.com/editor-controlled.js',
                        '//cdn.example.com/protocol-relative.js',
                        'javascript:alert(1)',
                    ],
                ],
            ],
        ],
    ];

    $group = (new ThemeResourceResolver)->group($theme, 'theme.carousel');

    expect($group)->toBeInstanceOf(FrontendResourceGroupData::class)
        ->and($group?->label)->toBe('Carousel')
        ->and($group?->package)->toBe('capell-app/theme-metadata')
        ->and($group?->resources)->toHaveCount(2)
        ->and($group?->resources[0]->kind)->toBe(FrontendResourceKind::Style)
        ->and($group?->resources[0]->source)->toBeInstanceOf(PublicResourceSourceData::class)
        ->and($group?->resources[1]->loadingStrategy)->toBe(PresentationLoadingStrategy::Visible);
});

it('deduplicates repeated local resources', function (): void {
    $theme = new Theme;
    $theme->meta = ['editor' => ['resources' => ['gallery' => [
        'assets' => ['vendor/gallery.js', 'vendor/gallery.js'],
    ]]]];

    expect((new ThemeResourceResolver)->group($theme, 'gallery')?->resources)->toHaveCount(1);
});

it('uses registered trusted package groups without reconstructing declarations', function (): void {
    $resource = FrontendResourceData::style(
        'capell-app/gallery:styles',
        'capell-app/gallery',
        new PublicResourceSourceData('vendor/gallery/gallery.css'),
    );
    $group = new FrontendResourceGroupData('gallery', 'Gallery', 'capell-app/gallery', [$resource]);
    $registry = new FrontendResourceRegistry;
    $registry->register($group);

    expect((new ThemeResourceResolver($registry))->group(new Theme, 'gallery'))->toBe($group);
});
