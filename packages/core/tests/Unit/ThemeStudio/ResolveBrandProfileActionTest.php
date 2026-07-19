<?php

declare(strict_types=1);
use Capell\Core\ThemeStudio\Actions\ResolveBrandProfileAction;
use Capell\Core\ThemeStudio\Data\BrandProfileData;
use Capell\Core\ThemeStudio\Data\ThemeDefinitionData;
use Capell\Core\ThemeStudio\Data\ThemeOverrideData;
use Capell\Core\ThemeStudio\Data\ThemePresetData;

it('emits only declared identity token values and lets explicit overrides win', function (): void {
    $definition = new ThemeDefinitionData(
        key: 'liquid-glass',
        name: 'Liquid Glass',
        description: 'Glass theme.',
        package: 'capell-app/theme-liquid-glass',
        previewImage: '/preview.jpg',
        tags: [],
        bestFit: [],
        presets: [new ThemePresetData('clarity', 'Clarity', 'Clear.', '/preview.jpg', ['glassDepth' => 'balanced'])],
        frontend: [
            'editor' => [
                'groups' => ['identity' => ['glassDepth']],
                'tokens' => ['glassDepth' => ['options' => ['restrained', 'balanced', 'prismatic']]],
            ],
        ],
    );

    $profile = ResolveBrandProfileAction::run(
        new BrandProfileData,
        $definition,
        new ThemeOverrideData('liquid-glass', 'clarity', [
            'glassDepth' => 'prismatic',
            'undeclaredToken' => 'unsafe',
        ]),
    );

    expect($profile->tokens())
        ->toHaveKey('--theme-glass-depth', 'prismatic')
        ->not->toHaveKey('--theme-undeclared-token');
});

it('rejects identity token values outside the declared options', function (): void {
    $definition = new ThemeDefinitionData(
        key: 'liquid-glass',
        name: 'Liquid Glass',
        description: 'Glass theme.',
        package: 'capell-app/theme-liquid-glass',
        previewImage: '/preview.jpg',
        tags: [],
        bestFit: [],
        presets: [new ThemePresetData('clarity', 'Clarity', 'Clear.', '/preview.jpg', [])],
        frontend: [
            'editor' => [
                'groups' => ['identity' => ['glassDepth']],
                'tokens' => ['glassDepth' => ['options' => ['balanced']]],
            ],
        ],
    );

    $profile = ResolveBrandProfileAction::run(
        new BrandProfileData,
        $definition,
        new ThemeOverrideData('liquid-glass', 'clarity', ['glassDepth' => 'calc(999px)']),
    );

    expect($profile->tokens())->not->toHaveKey('--theme-glass-depth');
});

it('rejects unsafe and reserved custom token names', function (string $key): void {
    $definition = new ThemeDefinitionData(
        key: 'custom-token-safety',
        name: 'Custom Token Safety',
        description: 'Custom token safety test.',
        package: 'capell-app/custom-token-safety',
        previewImage: '/preview.jpg',
        tags: [],
        bestFit: [],
        presets: [new ThemePresetData('default', 'Default', 'Default.', '/preview.jpg', [])],
        frontend: [
            'editor' => [
                'tokens' => [$key => ['options' => ['unsafe']]],
            ],
        ],
    );

    $profile = ResolveBrandProfileAction::run(
        new BrandProfileData,
        $definition,
        new ThemeOverrideData('custom-token-safety', 'default', [$key => 'unsafe']),
    );

    expect($profile->customTokens)->toBeEmpty();
})->with([
    'css declaration injection' => 'x; } body { displayNone',
    'reserved derived property' => 'radiusValue',
]);

it('defensively excludes unsafe custom tokens when profiles are constructed directly', function (): void {
    $tokens = new BrandProfileData(customTokens: [
        'safeIdentity' => 'balanced',
        'x; } body { displayNone' => 'unsafe',
        'radiusValue' => '999px',
    ])->tokens();

    expect($tokens)
        ->toHaveKey('--theme-safe-identity', 'balanced')
        ->toHaveKey('--theme-radius-value', '0.5rem')
        ->not->toHaveKey('--theme-x; } body { display-none');
});
