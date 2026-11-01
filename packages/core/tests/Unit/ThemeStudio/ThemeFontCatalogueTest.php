<?php

declare(strict_types=1);

use Capell\Core\ThemeStudio\Assets\ThemeTokenValidator;
use Capell\Core\ThemeStudio\Data\BrandProfileData;
use Capell\Core\ThemeStudio\Typography\ThemeFontCatalogue;

it('resolves every catalogued font key to its own stack', function (string $key): void {
    $entry = ThemeFontCatalogue::all()[$key];
    $tokens = new BrandProfileData(headingFont: $key, bodyFont: $key)->tokens();

    expect($tokens['--theme-heading-font'])->toBe($entry['stack'])
        ->and($tokens['--theme-body-font'])->toBe($entry['stack']);
})->with(fn (): array => array_map(fn (string $key): array => [$key], array_keys(ThemeFontCatalogue::all())));

it('keeps every catalogued stack safe for public CSS and ending in a generic family', function (): void {
    $validator = new ThemeTokenValidator;

    foreach (ThemeFontCatalogue::all() as $key => $entry) {
        expect($validator->safeCssValue($entry['stack']))->toBeTrue($key . ' stack is not safe CSS')
            ->and($entry['stack'])->toMatch('/(serif|sans-serif|monospace)\z/');
    }
});

it('falls back to the default stack for an unknown or hostile key', function (): void {
    $default = ThemeFontCatalogue::stack(ThemeFontCatalogue::DEFAULT_KEY);

    expect(ThemeFontCatalogue::has('nope'))->toBeFalse()
        ->and(ThemeFontCatalogue::stack('nope'))->toBe($default)
        ->and(new BrandProfileData(headingFont: 'x}</style><script>')->tokens()['--theme-heading-font'])->toBe($default);
});
