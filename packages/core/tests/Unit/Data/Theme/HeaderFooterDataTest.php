<?php

declare(strict_types=1);

use Capell\Core\Data\Theme\ChromeColorsData;
use Capell\Core\Data\Theme\HeaderData;
use Capell\Core\Enums\HeaderPositionEnum;
use Capell\Core\Enums\MenuAlignmentEnum;

it('hydrates header with light and optional dark variants from legacy flat keys', function (): void {
    $data = HeaderData::fromLegacyMeta([
        'header' => true,
        'header_background_color' => '#ffffff',
        'header_border_color' => '#e4e4e4',
        'header_color' => '#222222',
        'header_dark_background_color' => '#111111',
        'header_dark_border_color' => '#333333',
        'header_dark_color' => '#eeeeee',
        'header_height' => '72',
        'header_position' => 'sticky',
        'header_menu_alignment' => 'right',
    ]);

    expect($data->enabled)->toBeTrue()
        ->and($data->light->backgroundColor)->toBe('#ffffff')
        ->and($data->dark?->backgroundColor)->toBe('#111111')
        ->and($data->height)->toBe('72')
        ->and($data->position?->value)->toBe('sticky');
});

it('nulls dark variant when all dark fields are empty', function (): void {
    $data = HeaderData::fromLegacyMeta([
        'header' => true,
        'header_background_color' => '#ffffff',
    ]);

    expect($data->dark)->toBeNull();
});

it('exports structured header data back to legacy theme meta keys', function (): void {
    $data = new HeaderData(
        enabled: false,
        light: new ChromeColorsData(
            backgroundColor: '#ffffff',
            borderColor: '#dddddd',
            color: '#111111',
        ),
        dark: new ChromeColorsData(
            backgroundColor: '#111111',
            borderColor: '#333333',
            color: '#eeeeee',
        ),
        file: 'theme::partials.header',
        height: '80',
        position: HeaderPositionEnum::Fixed,
        menuAlignment: MenuAlignmentEnum::Center,
    );

    expect($data->toLegacyMeta())->toBe([
        'header' => false,
        'header_background_color' => '#ffffff',
        'header_border_color' => '#dddddd',
        'header_color' => '#111111',
        'header_file' => 'theme::partials.header',
        'header_height' => '80',
        'header_position' => 'fixed',
        'header_menu_alignment' => 'center',
        'header_dark_background_color' => '#111111',
        'header_dark_border_color' => '#333333',
        'header_dark_color' => '#eeeeee',
    ]);
});
