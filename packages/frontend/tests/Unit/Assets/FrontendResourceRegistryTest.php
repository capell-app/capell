<?php

declare(strict_types=1);

use Capell\Core\Enums\PresentationLoadingStrategy;
use Capell\Frontend\Support\Assets\FrontendResourceRegistry;

it('registers resource groups with css and javascript assets', function (): void {
    $registry = new FrontendResourceRegistry;

    $registry->group('theme.carousel')
        ->css('resources/css/widgets/carousel.css', buildPath: 'vendor/theme')
        ->js('resources/js/widgets/carousel.js', buildPath: 'vendor/theme', loading: PresentationLoadingStrategy::Visible);

    $group = $registry->get('theme.carousel');

    expect($group?->key)->toBe('theme.carousel')
        ->and($group?->resources)->toHaveCount(2)
        ->and($group?->resources[1]->loadingStrategy)->toBe(PresentationLoadingStrategy::Visible);
});

it('deduplicates equivalent resources inside a group', function (): void {
    $registry = new FrontendResourceRegistry;

    $registry->group('theme.carousel')
        ->css('resources/css/widgets/carousel.css', buildPath: 'vendor/theme')
        ->css('resources/css/widgets/carousel.css', buildPath: 'vendor/theme');

    expect($registry->get('theme.carousel')?->resources)->toHaveCount(1);
});

it('registers package default groups with metadata and validation warnings', function (): void {
    $registry = new FrontendResourceRegistry;

    $registry->register(
        key: 'package.gallery',
        label: 'Gallery',
        assets: [
            ['source' => 'resources/css/gallery.css'],
            ['missing' => true],
        ],
        description: 'Gallery widget resources',
        package: 'capell-app/gallery',
        defaultBuildPath: 'vendor/gallery',
    );

    $group = $registry->get('package.gallery');

    expect($group?->label)->toBe('Gallery')
        ->and($group?->description)->toBe('Gallery widget resources')
        ->and($group?->package)->toBe('capell-app/gallery')
        ->and($group?->origin)->toBe('package')
        ->and($group?->validation->warnings)->toHaveCount(1)
        ->and($group?->resources[0]->buildPath)->toBe('vendor/gallery');
});
