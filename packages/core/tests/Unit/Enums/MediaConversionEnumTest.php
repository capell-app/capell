<?php

declare(strict_types=1);

use Capell\Core\Enums\MediaConversionEnum;
use Spatie\Image\Enums\Fit;

it('exposes stable media conversion defaults for registered image derivatives', function (): void {
    expect(MediaConversionEnum::values())->toBe(['thumbnail', 'small', 'medium', 'large'])
        ->and(MediaConversionEnum::values())->toBe(['thumbnail', 'small', 'medium', 'large'])
        ->and(MediaConversionEnum::defaultDimensionsByConversionValue())->toBe([
            'thumbnail' => ['width' => 320, 'height' => 320],
            'small' => ['width' => 640, 'height' => 640],
            'medium' => ['width' => 1280, 'height' => 1280],
            'large' => ['width' => 2560, 'height' => 2560],
        ])
        ->and(MediaConversionEnum::defaultDimensionsByConversionValue())->toHaveKey('large')
        ->and(MediaConversionEnum::Thumbnail->fit())->toBe(Fit::Crop)
        ->and(MediaConversionEnum::Large->fit())->toBe(Fit::Max)
        ->and(MediaConversionEnum::Medium->format())->toBe('webp')
        ->and(MediaConversionEnum::Small->defaultDimensions())->toBe(['width' => 640, 'height' => 640]);
});
