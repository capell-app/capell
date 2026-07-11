<?php

declare(strict_types=1);

use Capell\Core\Enums\DefaultColorEnum;
use Capell\Core\Models\Theme;
use Capell\Frontend\Actions\GetThemeColorsAction;
use Capell\Frontend\Data\ColorData;
use Illuminate\Database\Eloquent\Model;

it('merges theme colors with defaults, theme takes precedence', function (): void {
    $theme = Theme::factory()->make([
        'meta' => [
            'colors' => [
                'primary' => '#123456',
                'custom' => '#abcdef',
            ],
        ],
    ]);

    $result = GetThemeColorsAction::run($theme);

    $resultArray = $result->mapWithKeys(fn (ColorData $data): array => [$data->name => $data->color])->all();
    $defaults = DefaultColorEnum::getKeyValues();

    expect($resultArray['primary'])->toBe('#123456')
        ->and($resultArray['custom'])->toBe('#abcdef')
        ->and($resultArray['danger'])->toBe($defaults['danger']);
});

it('does not lazy load theme blueprint metadata while resolving colors', function (): void {
    $theme = Theme::factory()->make([
        'meta' => [
            'colors' => [
                'primary' => '#123456',
            ],
        ],
    ]);

    Model::preventLazyLoading();

    try {
        $result = GetThemeColorsAction::run($theme);
    } finally {
        Model::preventLazyLoading(false);
    }

    $resultArray = $result->mapWithKeys(fn (ColorData $data): array => [$data->name => $data->color])->all();

    expect($resultArray['primary'])->toBe('#123456');
});

it('returns only defaults if theme has no colors', function (): void {
    $theme = Theme::factory()->make(['meta' => []]);

    $result = GetThemeColorsAction::run($theme);

    $defaults = DefaultColorEnum::getKeyValues();
    $resultArray = $result->mapWithKeys(fn (ColorData $data): array => [$data->name => $data->color])->all();

    expect($resultArray)->toBe($defaults);
});
