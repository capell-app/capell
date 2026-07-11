<?php

declare(strict_types=1);

use Capell\Core\Data\Theme\ColorsData;

it('hydrates colors from an array payload', function (): void {
    $data = ColorsData::from([
        'link_color' => '#0066cc',
        'link_color_active' => '#004080',
        'divider_color' => '#e4e4e4',
        'dark_mode_toggle' => 'on',
        'colors' => [
            ['name' => 'primary', 'value' => '#0066cc'],
            ['name' => 'surface', 'value' => '#ffffff'],
        ],
    ]);

    expect($data->linkColor)->toBe('#0066cc')
        ->and($data->darkModeToggle)->toBe('on')
        ->and($data->palette)->toHaveCount(2)
        ->and($data->palette[0]->name)->toBe('primary');
});

it('returns an empty palette when no colors provided', function (): void {
    $data = ColorsData::from([]);

    expect($data->palette)->toBeArray()->toBeEmpty()
        ->and($data->linkColor)->toBeNull();
});
