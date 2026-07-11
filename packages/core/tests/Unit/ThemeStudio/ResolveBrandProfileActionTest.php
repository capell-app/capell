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
