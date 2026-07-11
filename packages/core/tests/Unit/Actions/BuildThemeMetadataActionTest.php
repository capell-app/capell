<?php

declare(strict_types=1);

use Capell\Core\Actions\BuildThemeMetadataAction;

it('builds editor metadata while preserving existing and intercepted values', function (): void {
    $metadata = BuildThemeMetadataAction::run(
        themeKey: 'editorial',
        existingMeta: [
            'custom' => 'preserved',
            'assets' => ['resources/css/existing.css'],
            'assets_path' => 'existing-build',
            'editor' => [
                'header' => ['variant' => 'compact', 'enabled' => false],
                'footer' => ['variant' => 'stacked'],
            ],
        ],
        data: [
            'meta' => [
                'custom' => 'intercepted',
                'editor' => [
                    'preset' => ['label' => 'Launch'],
                ],
            ],
        ],
        activePreset: 'launch',
    );

    expect($metadata)->toMatchArray([
        'custom' => 'intercepted',
        'assets' => ['resources/css/existing.css'],
        'assets_path' => 'existing-build',
        'active_preset' => 'launch',
        'editor' => [
            'assets' => [
                'paths' => ['resources/css/existing.css'],
                'buildPath' => 'existing-build',
            ],
            'preset' => [
                'label' => 'Launch',
                'active' => 'launch',
            ],
            'header' => ['enabled' => true],
            'footer' => ['enabled' => true],
        ],
    ]);
});

it('prioritizes explicit assets and build path over intercepted and existing metadata', function (): void {
    $metadata = BuildThemeMetadataAction::run(
        themeKey: 'brand',
        existingMeta: [
            'assets' => ['resources/css/existing.css'],
            'assets_path' => 'existing-build',
        ],
        data: [
            'assets' => ['resources/css/intercepted.css'],
            'assets_build_path' => 'intercepted-build',
        ],
        assets: ['resources/css/explicit.css'],
        assetsPath: 'explicit-build',
    );

    expect($metadata)->toMatchArray([
        'assets' => ['resources/css/explicit.css'],
        'assets_path' => 'explicit-build',
        'editor' => [
            'assets' => [
                'paths' => ['resources/css/explicit.css'],
                'buildPath' => 'explicit-build',
            ],
            'preset' => ['active' => 'default'],
            'header' => ['enabled' => true],
            'footer' => ['enabled' => true],
        ],
    ]);
});
