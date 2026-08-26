<?php

declare(strict_types=1);

use Capell\Core\Data\Media\MediaUploadConfigurationData;
use Capell\Core\Support\Media\SpatieMediaUploadConfigurationFactory;

it('builds neutral media configuration without a Filament field', function (): void {
    config()->set('capell.media.crop_presets', [
        'thumbnail' => [
            'label' => 'Thumbnail',
            'ratio' => '1:1',
            'width' => 320,
            'height' => 320,
        ],
        'hero' => [
            'label' => 'Hero',
            'ratio' => '16:9',
            'width' => 1600,
            'height' => 900,
        ],
    ]);

    $configuration = resolve(SpatieMediaUploadConfigurationFactory::class)->make('hero');

    expect($configuration)
        ->toBeInstanceOf(MediaUploadConfigurationData::class)
        ->and($configuration->name)->toBe('hero')
        ->and($configuration->collection)->toBe('hero')
        ->and($configuration->conversion)->toBe('thumbnail')
        ->and($configuration->panelLayout)->toBe('grid')
        ->and($configuration->responsiveImages)->toBeTrue()
        ->and($configuration->imageEditor)->toBeTrue()
        ->and($configuration->imageEditorMode)->toBe(2)
        ->and($configuration->aspectRatioOptions)->toBe([null, '1:1', '16:9'])
        ->and($configuration->disk)->toBe('public');
});
