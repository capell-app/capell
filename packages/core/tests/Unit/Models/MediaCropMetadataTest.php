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
