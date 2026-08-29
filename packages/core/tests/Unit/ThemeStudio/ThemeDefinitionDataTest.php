<?php

declare(strict_types=1);

use Capell\Core\ThemeStudio\Data\ThemeDefinitionData;
use Capell\Core\ThemeStudio\Data\ThemePresetData;

function themeDefinitionDataWithFrontend(array $frontend): ThemeDefinitionData
{
    return new ThemeDefinitionData(
        key: 'accessor-theme',
        name: 'Accessor theme',
        description: 'Accessor theme.',
        package: 'capell-app/theme-accessor-theme',
        previewImage: '/preview.jpg',
        tags: [],
        bestFit: [],
        presets: [
            new ThemePresetData(
                key: 'default',
                name: 'Default',
                description: 'Default preset.',
                previewImage: '/preset.jpg',
            ),
        ],
        frontend: $frontend,
    );
}

it('returns the frontend editor array when present', function (): void {
    $editor = [
        'groups' => ['color' => ['brand.primary']],
        'tokens' => ['brand.primary' => ['options' => ['blue', 'green']]],
    ];

    $definition = themeDefinitionDataWithFrontend(['editor' => $editor]);

    expect($definition->frontendEditor())->toBe($editor);
});

it('returns an empty array when the frontend editor is absent', function (): void {
    $definition = themeDefinitionDataWithFrontend([]);

    expect($definition->frontendEditor())->toBe([]);
});

it('returns an empty array when the frontend editor is not an array', function (): void {
    $definition = themeDefinitionDataWithFrontend(['editor' => 'not-an-array']);

    expect($definition->frontendEditor())->toBe([]);
});

it('returns the frontend editor groups when present', function (): void {
    $groups = ['color' => ['brand.primary', 'brand.secondary']];

    $definition = themeDefinitionDataWithFrontend(['editor' => ['groups' => $groups]]);

    expect($definition->frontendEditorGroups())->toBe($groups);
});

it('returns an empty array when the frontend editor groups are absent', function (): void {
    $definition = themeDefinitionDataWithFrontend(['editor' => []]);

    expect($definition->frontendEditorGroups())->toBe([]);
});

it('returns an empty array when the frontend editor groups are not an array', function (): void {
    $definition = themeDefinitionDataWithFrontend(['editor' => ['groups' => 'not-an-array']]);

    expect($definition->frontendEditorGroups())->toBe([]);
});

it('returns the frontend editor tokens when present', function (): void {
    $tokens = ['brand.primary' => ['options' => ['blue', 'green']]];

    $definition = themeDefinitionDataWithFrontend(['editor' => ['tokens' => $tokens]]);

    expect($definition->frontendEditorTokens())->toBe($tokens);
});

it('returns an empty array when the frontend editor tokens are absent', function (): void {
    $definition = themeDefinitionDataWithFrontend(['editor' => []]);

    expect($definition->frontendEditorTokens())->toBe([]);
});

it('returns an empty array when the frontend editor tokens are not an array', function (): void {
    $definition = themeDefinitionDataWithFrontend(['editor' => ['tokens' => 'not-an-array']]);

    expect($definition->frontendEditorTokens())->toBe([]);
});
