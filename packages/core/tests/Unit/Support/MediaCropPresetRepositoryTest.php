<?php

declare(strict_types=1);

use Capell\Core\Support\Media\MediaCropPresetRepository;

it('normalizes configured crop presets for conversions and editor ratios', function (): void {
    config()->set('capell.media.crop_presets', [
        'thumbnail' => [
            'label' => 'Thumbnail',
            'ratio' => '1:1',
            'width' => 320,
            'height' => 320,
        ],
        'broken' => [
            'ratio' => '16:9',
            'width' => 0,
            'height' => 900,
        ],
        'hero' => [
            'ratio' => '16:9',
            'width' => 1600,
            'height' => 900,
        ],
    ]);

    $repository = new MediaCropPresetRepository;

    expect($repository->all())
        ->toHaveKeys(['thumbnail', 'hero'])
        ->not->toHaveKey('broken')
        ->and($repository->options())
        ->toBe([
            'thumbnail' => 'Thumbnail (1:1)',
            'hero' => 'Hero (16:9)',
        ])
        ->and($repository->aspectRatioOptions())
        ->toBe([null, '1:1', '16:9']);
});
