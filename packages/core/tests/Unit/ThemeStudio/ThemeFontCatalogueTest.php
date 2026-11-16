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

it('gives every family a role that matches the generic family its stack ends in', function (): void {
    $generic = ['sans' => 'sans-serif', 'serif' => 'serif', 'mono' => 'monospace', 'system' => 'sans-serif'];

    foreach (ThemeFontCatalogue::all() as $key => $entry) {
        expect($entry['role'])->toBeIn(ThemeFontCatalogue::ROLES, $key . ' has no known role')
            ->and(str_ends_with($entry['stack'], $generic[$entry['role']]))->toBeTrue($key . ' role does not match its stack')
            ->and(ThemeFontCatalogue::role($key))->toBe($entry['role']);
    }

    expect(ThemeFontCatalogue::options())->toHaveKeys(array_keys(ThemeFontCatalogue::all()))
        ->and(ThemeFontCatalogue::options()['source-serif'])->toBe('Source Serif 4');
});

it('reports the font keys a definition uses that the catalogue does not hold', function (array $values, ?string $pairing, array $unknown): void {
    expect(new ThemeTokenValidator()->unknownFontKeys($values, $pairing))->toBe($unknown);
})->with([
    'catalogue keys' => [['headingFont' => 'fraunces', 'bodyFont' => 'source-serif'], 'archivo+inter', []],
    'a retired Brutalist key' => [['headingFont' => 'sans', 'bodyFont' => 'mono'], null, ['sans', 'mono']],
    'a misspelt pairing' => [[], 'fraunce+source-serif', ['fraunce']],
    'a one-key pairing' => [[], 'newsreader', []],
    'an empty or non-string value' => [['headingFont' => '', 'bodyFont' => 12], null, ['']],
    'no font values' => [['primaryColor' => '#000000'], null, []],
]);
