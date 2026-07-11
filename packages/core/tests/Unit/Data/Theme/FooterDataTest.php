<?php

declare(strict_types=1);

use Capell\Core\Data\Theme\ChromeColorsData;
use Capell\Core\Data\Theme\FooterData;

it('maps legacy footer meta into structured theme data and back', function (): void {
    $footer = FooterData::fromLegacyMeta([
        'footer' => false,
        'footer_background_color' => '#ffffff',
        'footer_border_color' => '#e5e7eb',
        'footer_color' => '#111827',
        'footer_dark_background_color' => '#111827',
        'footer_dark_border_color' => '#374151',
        'footer_dark_color' => '#f9fafb',
        'footer_file' => 'partials/footer.blade.php',
    ]);

    expect($footer->enabled)->toBeFalse()
        ->and($footer->light->backgroundColor)->toBe('#ffffff')
        ->and($footer->dark)->toBeInstanceOf(ChromeColorsData::class)
        ->and($footer->dark?->color)->toBe('#f9fafb')
        ->and($footer->file)->toBe('partials/footer.blade.php')
        ->and($footer->toLegacyMeta())->toMatchArray([
            'footer' => false,
            'footer_background_color' => '#ffffff',
            'footer_border_color' => '#e5e7eb',
            'footer_color' => '#111827',
            'footer_dark_background_color' => '#111827',
            'footer_dark_border_color' => '#374151',
            'footer_dark_color' => '#f9fafb',
            'footer_file' => 'partials/footer.blade.php',
        ]);
});

it('omits dark footer meta when no dark colors are configured', function (): void {
    $footer = FooterData::fromLegacyMeta([
        'footer_background_color' => '#ffffff',
    ]);

    expect($footer->enabled)->toBeTrue()
        ->and($footer->dark)->toBeNull()
        ->and($footer->toLegacyMeta())->not->toHaveKeys([
            'footer_dark_background_color',
            'footer_dark_border_color',
            'footer_dark_color',
        ]);
});
