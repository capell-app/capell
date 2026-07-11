<?php

declare(strict_types=1);

use Capell\Core\Data\Theme\AssetsData;

it('hydrates assets from flat meta', function (): void {
    $data = AssetsData::from([
        'assets_build_path' => '/build',
        'critical_asset' => 'critical.css',
        'assets' => [
            ['name' => 'app', 'path' => '/build/app.css', 'type' => 'css'],
            ['name' => 'app-js', 'path' => '/build/app.js', 'type' => 'js'],
        ],
    ]);

    expect($data->assetsBuildPath)->toBe('/build')
        ->and($data->criticalAsset)->toBe('critical.css')
        ->and($data->assets)->toHaveCount(2)
        ->and($data->assets[0]->name)->toBe('app')
        ->and($data->assets[0]->type)->toBe('css');
});

it('defaults to empty assets list', function (): void {
    $data = AssetsData::from([]);

    expect($data->assets)->toBeArray()->toBeEmpty()
        ->and($data->assetsBuildPath)->toBeNull()
        ->and($data->criticalAsset)->toBeNull();
});
