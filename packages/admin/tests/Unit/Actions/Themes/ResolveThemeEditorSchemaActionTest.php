<?php

declare(strict_types=1);

use Capell\Admin\Actions\Themes\ResolveThemeEditorSchemaAction;
use Capell\Admin\Data\Themes\ThemeEditorGroupData;
use Capell\Admin\Data\Themes\ThemeEditorSchemaData;
use Capell\Admin\Data\Themes\ThemeEditorStateData;
use Capell\Admin\Data\Themes\ThemeEditorTokenData;
use Capell\Admin\Filament\Configurators\Themes\FoundationThemeConfigurator;
use Capell\Core\Models\Theme;
use Capell\Core\ThemeStudio\Actions\ResolveBrandProfileAction;
use Capell\Core\ThemeStudio\Data\BrandProfileData;
use Capell\Core\ThemeStudio\Data\ThemeDefinitionData;
use Capell\Core\ThemeStudio\Data\ThemeOverrideData;
use Capell\Core\ThemeStudio\Data\ThemePresetData;
use Filament\Forms\Components\Select;
use Illuminate\Contracts\Translation\Translator as TranslatorContract;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;

beforeEach(function (): void {
    $loader = new ArrayLoader;
    $loader->addMessages('en', 'theme-editor', [
        'sections.surface' => 'Page surface',
        'options.use_preset' => 'Use preset default',
        'descriptions.schema_group' => 'Choose an explicit override.',
        'schema_labels.layout' => 'Layout',
        'schema_labels.typography' => 'Typography',
        'schema_labels.media' => 'Media',
    ], 'capell-admin');
    $loader->addMessages('en', 'generic', ['compact' => 'Compact'], 'capell-admin');
    $translator = new Translator($loader, 'en');

    app()->instance('translator', $translator);
    app()->instance(TranslatorContract::class, $translator);
});

it('resolves translated layout typography and media groups with closed token options', function (): void {
    $schema = resolve(ResolveThemeEditorSchemaAction::class)->handle(themeEditorDefinition());

    expect($schema)->toBeInstanceOf(ThemeEditorSchemaData::class)
        ->and(collect($schema->groups)->map->key->all())->toBe(['layout', 'typography', 'media'])
        ->and($schema->groups[0]->label)->toBe('Layout')
        ->and($schema->tokensByKey())->toHaveCount(3)
        ->and($schema->tokensByKey()['headingScale']->optionsByValue())->toBe([
            'compact' => 'Compact',
            'expressive' => 'Expressive',
        ]);
});

it('builds exactly one closed select control for every declared token', function (): void {
    $schema = resolve(ResolveThemeEditorSchemaAction::class)->handle(themeEditorDefinition());
    $configurator = new class extends FoundationThemeConfigurator
    {
        /** @return array<int, Select> */
        public function controls(ThemeEditorGroupData $group): array
        {
            return collect($group->tokens)
                ->map(fn (ThemeEditorTokenData $token): Select => $this->tokenControl($token))
                ->all();
        }
    };

    $controls = collect($schema->groups)->flatMap($configurator->controls(...))->values();
    $firstControl = $controls->first();

    assert($firstControl instanceof Select);

    expect($controls)->toHaveCount(3)
        ->and($controls->map(fn (Select $control): string => $control->getName())->all())
        ->toBe(['layoutPresentation', 'headingScale', 'mediaTreatment'])
        ->and($firstControl->getOptions())->toBe([
            'structured' => 'Structured',
            'immersive' => 'Immersive',
        ]);
});

it('rejects unknown groups duplicate tokens and free text tokens', function (array $frontend, string $message): void {
    $definition = themeEditorDefinition($frontend);

    expect(fn (): ThemeEditorSchemaData => resolve(ResolveThemeEditorSchemaAction::class)->handle($definition))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'unknown group' => [
        ['editor' => ['groups' => ['effects' => ['unknown']], 'tokens' => []]],
        'references unknown token [unknown]',
    ],
    'free text token' => [
        ['editor' => ['groups' => ['layout' => ['custom']], 'tokens' => ['custom' => []]]],
        'must declare allowed options',
    ],
    'duplicate token' => [
        ['editor' => ['groups' => [
            'layout' => ['density'],
            'media' => ['density'],
        ], 'tokens' => ['density' => ['options' => ['compact']]]]],
        'is declared in more than one group',
    ],
]);

it('applies preset defaults before explicit declared token selections', function (): void {
    $definition = themeEditorDefinition();
    $schema = resolve(ResolveThemeEditorSchemaAction::class)->handle($definition);
    $selection = ['headingScale' => 'expressive'];

    expect($schema->tokensByKey()['headingScale']->accepts($selection['headingScale']))->toBeTrue()
        ->and($schema->tokensByKey()['headingScale']->accepts('anything-goes'))->toBeFalse();

    $profile = ResolveBrandProfileAction::run(
        brand: new BrandProfileData,
        definition: $definition,
        override: new ThemeOverrideData(
            themeKey: $definition->key,
            presetKey: 'launch',
            values: $selection,
        ),
    );

    expect($profile->layoutPresentation)->toBe('structured')
        ->and($profile->headingScale)->toBe('expressive');
});

it('drops unknown tokens and values when hydrating persisted editor state', function (): void {
    $theme = new Theme;
    $theme->forceFill([
        'meta' => [
            'editor' => [
                'tokens' => [
                    'headingScale' => 'expressive',
                    'mediaTreatment' => 'free text',
                    'undeclaredToken' => 'anything',
                ],
            ],
        ],
        'admin' => [],
    ]);

    $state = ThemeEditorStateData::forTheme($theme, themeEditorDefinition());

    expect($state->tokens)->toBe(['headingScale' => 'expressive'])
        ->and($state->metaEditor()['tokens'])->toBe(['headingScale' => 'expressive']);
});

/** @param array<string, mixed>|null $frontend */
function themeEditorDefinition(?array $frontend = null): ThemeDefinitionData
{
    return new ThemeDefinitionData(
        key: 'schema-theme',
        name: 'Schema Theme',
        description: 'Schema-driven theme.',
        package: 'capell-app/theme-schema',
        previewImage: '/schema.jpg',
        tags: [],
        bestFit: [],
        presets: [
            new ThemePresetData(
                key: 'launch',
                name: 'Launch',
                description: 'Launch preset.',
                previewImage: '/launch.jpg',
                values: [
                    'layoutPresentation' => 'structured',
                    'headingScale' => 'compact',
                    'mediaTreatment' => 'natural',
                ],
            ),
        ],
        frontend: $frontend ?? [
            'editor' => [
                'groups' => [
                    'layout' => ['layoutPresentation'],
                    'typography' => ['headingScale'],
                    'media' => ['mediaTreatment'],
                ],
                'tokens' => [
                    'layoutPresentation' => ['options' => ['structured', 'immersive']],
                    'headingScale' => ['options' => ['compact', 'expressive']],
                    'mediaTreatment' => ['options' => ['natural', 'editorial']],
                ],
            ],
        ],
    );
}
