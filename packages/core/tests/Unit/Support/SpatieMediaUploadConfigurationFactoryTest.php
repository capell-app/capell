<?php

declare(strict_types=1);

use Capell\Core\Data\Media\MediaUploadConfigurationData;
use Capell\Core\Support\Media\SpatieMediaUploadConfigurationFactory;

it('builds neutral media configuration without a Filament field', function (): void {
    $configuration = resolve(SpatieMediaUploadConfigurationFactory::class)->make('hero');

    expect($configuration)
        ->toBeInstanceOf(MediaUploadConfigurationData::class)
        ->and($configuration->name)->toBe('hero')
        ->and($configuration->collection)->toBe('hero')
        ->and($configuration->conversion)->toBe('thumbnail')
        ->and($configuration->panelLayout)->toBe('grid')
        ->and($configuration->responsiveImages)->toBeTrue()
        ->and($configuration->imageEditor)->toBeTrue()
        ->and($configuration->disk)->toBe('public');
});
