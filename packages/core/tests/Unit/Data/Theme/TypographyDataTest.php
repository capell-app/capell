<?php

declare(strict_types=1);

use Capell\Core\Data\Theme\TypographyData;
use Capell\Core\Enums\FontTypeEnum;

it('hydrates fonts with url and local variants', function (): void {
    $data = TypographyData::from([
        'fonts' => [
            ['type' => 'url', 'name' => 'Inter', 'weight' => '400', 'style' => 'normal', 'url' => 'https://fonts.googleapis.com/inter.css'],
            ['type' => 'local', 'name' => 'Brand', 'weight' => '700', 'style' => 'italic', 'files' => ['brand.woff2']],
        ],
        'font_family' => 'Inter',
        'font_heading_family' => 'Brand',
    ]);

    expect($data->fonts)->toHaveCount(2)
        ->and($data->fonts[0]->type)->toBe(FontTypeEnum::Url)
        ->and($data->fonts[1]->files)->toBe(['brand.woff2'])
        ->and($data->fontFamily)->toBe('Inter');
});

it('defaults to empty fonts array', function (): void {
    $data = TypographyData::from([]);

    expect($data->fonts)->toBeArray()->toBeEmpty()
        ->and($data->fontFamily)->toBeNull();
});
