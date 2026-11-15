<?php

declare(strict_types=1);

use Capell\Core\ThemeStudio\Data\ThemePresetData;

it('defaults to a version 1 colour-only preset', function (): void {
    $preset = new ThemePresetData('default', 'Default', 'Default.', '/preview.jpg', ['primaryColor' => '#123456']);

    expect($preset->version)->toBe(1)
        ->and($preset->typePairing)->toBeNull()
        ->and($preset->layout)->toBeNull()
        ->and($preset->sectionVariants)->toBe([])
        ->and($preset->composesLook())->toBeFalse()
        ->and($preset->sectionVariant('hero'))->toBeNull();
});

it('describes a whole look with type pairing, layout and section variants', function (): void {
    $preset = new ThemePresetData(
        key: 'editorial',
        name: 'Editorial',
        description: 'Serif headings over a narrow column.',
        previewImage: '/editorial.jpg',
        version: 2,
        typePairing: 'serif-display',
        layout: 'narrow',
        sectionVariants: ['hero' => 'split', 'features' => 'list'],
    );

    expect($preset->composesLook())->toBeTrue()
        ->and($preset->sectionVariant('hero'))->toBe('split')
        ->and($preset->sectionVariant('footer'))->toBeNull()
        ->and(new ThemePresetData('t', 'T', 'T.', '/t.jpg', typePairing: 'sans')->composesLook())->toBeTrue()
        ->and(new ThemePresetData('l', 'L', 'L.', '/l.jpg', layout: 'wide')->composesLook())->toBeTrue();
});

it('stores only the values an editor changed from the preset', function (): void {
    $preset = new ThemePresetData('default', 'Default', 'Default.', '/preview.jpg', [
        'primaryColor' => '#123456',
        'radius' => 'soft',
    ]);

    expect($preset->diffOverrides([
        'primaryColor' => '#123456',
        'radius' => 'sharp',
        'accentColor' => '#abcdef',
    ]))->toBe([
        'radius' => 'sharp',
        'accentColor' => '#abcdef',
    ]);
});

it('keeps customised values and updates untouched ones when the preset is upgraded', function (): void {
    $installed = new ThemePresetData('default', 'Default', 'Default.', '/preview.jpg', [
        'primaryColor' => '#123456',
        'radius' => 'soft',
    ]);
    $stored = $installed->diffOverrides(['primaryColor' => '#123456', 'radius' => 'sharp']);

    $upgraded = new ThemePresetData('default', 'Default', 'Default.', '/preview.jpg', [
        'primaryColor' => '#0f766e',
        'radius' => 'soft',
    ], version: 2);

    expect([...$upgraded->values, ...$stored])->toBe([
        'primaryColor' => '#0f766e',
        'radius' => 'sharp',
    ]);
});

it('round-trips the whole-look fields through array data', function (): void {
    $preset = ThemePresetData::from([
        'key' => 'bold',
        'name' => 'Bold',
        'description' => 'Bold.',
        'previewImage' => '/bold.jpg',
        'version' => 3,
        'typePairing' => 'grotesk',
        'layout' => 'wide',
        'sectionVariants' => ['hero' => 'full-bleed'],
    ]);

    expect($preset->toArray())->toMatchArray([
        'version' => 3,
        'typePairing' => 'grotesk',
        'layout' => 'wide',
        'sectionVariants' => ['hero' => 'full-bleed'],
    ]);
});

it('expands the type pairing and layout into brand values under explicit values', function (): void {
    $preset = new ThemePresetData('look', 'Look', 'Look.', '/look.jpg', values: ['bodyFont' => 'source-serif'], typePairing: 'fraunces+inter', layout: 'editorial');

    expect($preset->resolvedValues())->toBe([
        'headingFont' => 'fraunces',
        'bodyFont' => 'source-serif',
        'layoutPresentation' => 'editorial',
    ])
        ->and(new ThemePresetData('one', 'One', 'One.', '/one.jpg', typePairing: 'newsreader')->resolvedValues())
        ->toBe(['headingFont' => 'newsreader', 'bodyFont' => 'newsreader'])
        ->and(new ThemePresetData('bad', 'Bad', 'Bad.', '/bad.jpg', typePairing: 'not-a-font+inter')->resolvedValues())
        ->toBe(['bodyFont' => 'inter']);
});

it('stores an editor font choice that matches the pairing as untouched', function (): void {
    $preset = new ThemePresetData('look', 'Look', 'Look.', '/look.jpg', typePairing: 'fraunces+inter');

    expect($preset->diffOverrides(['headingFont' => 'fraunces', 'bodyFont' => 'manrope']))->toBe(['bodyFont' => 'manrope']);
});
