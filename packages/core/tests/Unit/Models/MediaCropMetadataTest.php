<?php

declare(strict_types=1);

use Capell\Core\Models\Media;

it('stores and clamps focal point metadata', function (): void {
    $media = new Media;

    $media->setFocalPoint(-12, 140);

    expect($media->getFocalPoint())
        ->toBe(['x' => 0, 'y' => 100])
        ->and(data_get($media->custom_properties, 'capell.focal'))
        ->toBe(['x' => 0, 'y' => 100]);
});

it('stores crop presets using the current focal point', function (): void {
    $media = new Media;

    $media
        ->setFocalPoint(35, 65)
        ->setCropPresets(['thumbnail', 'hero', 'thumbnail', '']);

    expect($media->getCropPresetNames())
        ->toBe(['thumbnail', 'hero'])
        ->and($media->getFocalPointForConversion('hero'))
        ->toBe(['x' => 35, 'y' => 65])
        ->and($media->getFocalPointForConversion('missing'))
        ->toBe(['x' => 35, 'y' => 65]);
});

it('lists crop presets in registered order whatever order storage returns them in', function (): void {
    config()->set('capell.media.crop_presets', [
        'thumbnail' => ['ratio' => '1:1', 'width' => 400, 'height' => 400],
        'hero' => ['ratio' => '16:9', 'width' => 1600, 'height' => 900],
    ]);
    $media = new Media;
    // A MySQL JSON column returns object keys shortest first, so 'hero' comes back before 'thumbnail'.
    $media->custom_properties = ['capell' => ['crops' => [
        'retired' => ['focal' => ['x' => 50, 'y' => 50]],
        'hero' => ['focal' => ['x' => 50, 'y' => 50]],
        'archived' => ['focal' => ['x' => 50, 'y' => 50]],
        'thumbnail' => ['focal' => ['x' => 50, 'y' => 50]],
    ]]];

    expect($media->getCropPresetNames())->toBe(['thumbnail', 'hero', 'archived', 'retired']);
});
