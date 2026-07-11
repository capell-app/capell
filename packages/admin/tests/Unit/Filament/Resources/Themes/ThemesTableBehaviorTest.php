<?php

declare(strict_types=1);

use Capell\Admin\Actions\Themes\CreateThemePreviewUrlAction;
use Capell\Admin\Actions\Themes\SetActiveThemeForSitesAction;
use Capell\Admin\Data\Themes\SetActiveThemeForSitesData;
use Capell\Admin\Enums\Themes\ThemeActivationScope;
use Capell\Admin\Filament\Resources\Themes\Tables\ThemesTable;
use Capell\Admin\Tests\Unit\Filament\Resources\Themes\Fixtures\ThemesTableBehaviorLivewire;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Core\ThemeStudio\Data\ThemeDefinitionData;
use Capell\Core\ThemeStudio\Data\ThemePresetData;
use Capell\Core\ThemeStudio\Rendering\BladeThemeRenderer;
use Capell\Core\ThemeStudio\Theme\ThemeRegistry;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Component as SchemaComponent;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    test()->actingAsAdmin();

    Blueprint::factory()->theme()->default()->create();
});

it('evaluates theme table columns and action form choices from real admin records', function (): void {
    $theme = Theme::factory()->create([
        'key' => 'campaign-studio',
        'name' => 'Campaign Studio',
        'status' => true,
        'meta' => [
            'editor' => [
                'preset' => [
                    'active' => 'launch',
                ],
            ],
        ],
    ]);

    registerThemesTableDefinitionForTest($theme);

    $site = Site::factory()->theme($theme)->default()->create(['name' => 'Primary Site']);
    $page = Page::factory()->site($site)->create(['name' => 'Home Page']);

    $livewire = new ThemesTableBehaviorLivewire;
    $table = ThemesTable::configure(Table::make($livewire));
    new ReflectionProperty($livewire, 'table')->setValue($livewire, $table);
    $columns = collect($table->getColumns())
        ->filter(fn (mixed $column): bool => $column instanceof Column)
        ->keyBy(fn (Column $column): string => $column->getName());

    $nameColumn = $columns->get('name');
    $presetColumn = $columns->get('editor_active_preset');
    $diagnosticsColumn = $columns->get('diagnostics');
    $packageColumn = $columns->get('package');

    assert($nameColumn instanceof TextColumn);
    assert($presetColumn instanceof Column);
    assert($diagnosticsColumn instanceof TextColumn);
    assert($packageColumn instanceof Column);

    expect($table->getContentGrid())->toBe(['md' => 2, 'xl' => 3])
        ->and($table->getRecordClasses($theme))->toContain('capell-theme-card-record')
        ->and($nameColumn->record($theme)->getState())->toBe('Campaign Studio')
        ->and($presetColumn->record($theme)->getState())->toBe('Launch')
        ->and($diagnosticsColumn->record($theme)->getState())->toBe(__('capell-admin::theme-library.labels.diagnostics_warning'))
        ->and($diagnosticsColumn->record($theme)->getColor($diagnosticsColumn->getState()))->toBe('warning')
        ->and($packageColumn->record($theme)->getState())->toBe('capell-app/campaign-studio');

    $actions = themeTableActionsFromTableForTest($table, $theme);
    $previewAction = $actions['previewTheme'];
    $applyAction = $actions['applyTheme'];
    $diagnosticsAction = $actions['viewThemeDiagnostics'];

    expect(stringableThemeTableValue($previewAction->getModalHeading()))->toContain('Campaign Studio')
        ->and($previewAction->isIconButton())->toBeTrue()
        ->and($previewAction->call(['data' => ['site_id' => 0, 'page_id' => 0]]))->toBeNull()
        ->and(stringableThemeTableValue($applyAction->getModalHeading()))->toContain('Campaign Studio')
        ->and($applyAction->isIconButton())->toBeTrue()
        ->and($applyAction->isDisabled())->toBeFalse()
        ->and($diagnosticsAction->isIconButton())->toBeTrue()
        ->and($diagnosticsAction->getColor())->toBe('warning');

    $previewComponents = themeActionSchemaComponents($previewAction, $theme, $livewire);
    $applyComponents = themeActionSchemaComponents($applyAction, $theme, $livewire);

    $previewComponents['site_id']->state($site->getKey());
    $previewComponents['page_id']->state($page->getKey());
    $applyComponents['site_ids']->state([$site->getKey()]);

    assert($previewComponents['site_id'] instanceof Select);
    assert($previewComponents['page_id'] instanceof Select);
    assert($previewComponents['preset_key'] instanceof Select);
    assert($applyComponents['scope'] instanceof Select);
    assert($applyComponents['site_ids'] instanceof Select);

    expect($previewComponents['site_id']->getOptions())->toHaveKey($site->getKey())
        ->and($previewComponents['site_id']->getSearchResults('Primary'))->toHaveKey($site->getKey())
        ->and($previewComponents['site_id']->getOptionLabel())->toBe('Primary Site')
        ->and($previewComponents['preset_key']->getOptions())->toBe(['launch' => 'Launch'])
        ->and($applyComponents['scope']->getOptions())->toHaveKey(ThemeActivationScope::Global->value)
        ->and($applyComponents['site_ids']->getOptionLabels())->toBe([$site->getKey() => 'Primary Site']);
});

it('opens theme previews only for scoped sites and pages', function (): void {
    $theme = Theme::factory()->create([
        'key' => 'campaign-studio',
        'name' => 'Campaign Studio',
    ]);
    registerThemesTableDefinitionForTest($theme);

    $site = Site::factory()->default()->create(['name' => 'Primary Site']);
    $page = Page::factory()->site($site)->create(['name' => 'Home Page']);
    $otherPage = Page::factory()->create(['name' => 'Other Site Page']);
    $previewSpy = bindFakeAction(CreateThemePreviewUrlAction::class, 'https://preview.example.test/signed');

    $action = themeTableActionsForTest($theme)['previewTheme']->record($theme);

    $response = $action->call(['data' => [
        'site_id' => $site->getKey(),
        'page_id' => $page->getKey(),
        'preset_key' => 'launch',
    ]]);

    expect($response->getTargetUrl())->toBe('https://preview.example.test/signed')
        ->and($previewSpy->called)->toBeTrue()
        ->and($previewSpy->args['theme'])->toBe($theme)
        ->and($previewSpy->args['site']->is($site))->toBeTrue()
        ->and($previewSpy->args['page']->is($page))->toBeTrue()
        ->and($previewSpy->args['presetKey'])->toBe('launch')
        ->and($action->call(['data' => ['site_id' => $site->getKey(), 'page_id' => $otherPage->getKey()]]))->toBeNull()
        ->and($action->call(['data' => ['site_id' => 0, 'page_id' => $page->getKey()]]))->toBeNull();
});

it('validates theme activation scope against the current actor site access', function (): void {
    $theme = Theme::factory()->create([
        'key' => 'site-theme',
        'name' => 'Site Theme',
    ]);
    registerThemesTableDefinitionForTest($theme);

    $assignedSite = Site::factory()->create(['name' => 'Assigned Site']);
    $blockedSite = Site::factory()->create(['name' => 'Blocked Site']);
    $user = test()->createUser();
    $user->assignedSiteIds = collect([$assignedSite->getKey()]);

    test()->actingAs($user);

    $applySpy = bindFakeAction(SetActiveThemeForSitesAction::class, $theme);
    $action = themeTableActionsForTest($theme)['applyTheme']->record($theme);

    expect(fn (): mixed => $action->call(['data' => [
        'scope' => ThemeActivationScope::Global->value,
        'site_ids' => [],
    ]]))->toThrow(ValidationException::class)
        ->and(fn (): mixed => $action->call(['data' => [
            'scope' => ThemeActivationScope::SelectedSites->value,
            'site_ids' => [],
        ]]))->toThrow(ValidationException::class)
        ->and(fn (): mixed => $action->call(['data' => [
            'scope' => ThemeActivationScope::SelectedSites->value,
            'site_ids' => [$blockedSite->getKey()],
        ]]))->toThrow(ValidationException::class);

    $action->call(['data' => [
        'scope' => ThemeActivationScope::SelectedSites->value,
        'site_ids' => [(string) $assignedSite->getKey(), 'not-a-site-id'],
    ]]);

    $payload = $applySpy->args[0] ?? null;

    expect($applySpy->called)->toBeTrue()
        ->and($payload)->toBeInstanceOf(SetActiveThemeForSitesData::class)
        ->and($payload->themeId)->toBe($theme->getKey())
        ->and($payload->scope)->toBe(ThemeActivationScope::SelectedSites)
        ->and($payload->siteIds)->toBe([$assignedSite->getKey()]);
});

it('resolves theme preview and apply options from scoped sites and pages', function (): void {
    app()->instance(ThemeRegistry::class, new ThemeRegistry);

    $theme = Theme::factory()->create([
        'key' => 'unregistered-theme',
        'name' => 'Unregistered Theme',
        'meta' => [
            'editor' => [
                'preset' => [
                    'active' => 'fallback-preset',
                ],
            ],
        ],
    ]);

    $firstSite = Site::factory()->create([
        'name' => 'Alpha Site',
        'default' => false,
    ]);
    Site::factory()->create([
        'name' => 'Beta Site',
        'default' => false,
    ]);

    $page = Page::factory()->site($firstSite)->create(['name' => 'Landing Page']);
    Page::factory()->site($firstSite)->create(['name' => 'Article Page']);

    $livewire = new ThemesTableBehaviorLivewire;
    $actions = themeTableActionsForTest($theme, $livewire);

    $previewComponents = themeActionSchemaComponents($actions['previewTheme'], $theme, $livewire);
    $applyComponents = themeActionSchemaComponents($actions['applyTheme'], $theme, $livewire);

    assert($previewComponents['site_id'] instanceof Select);
    assert($previewComponents['page_id'] instanceof Select);
    assert($previewComponents['preset_key'] instanceof Select);
    assert($applyComponents['scope'] instanceof Select);
    assert($applyComponents['site_ids'] instanceof Select);

    expect($previewComponents['site_id']->getDefaultState())->toBe($firstSite->getKey())
        ->and($previewComponents['page_id']->getDefaultState())->toBe($page->getKey())
        ->and($previewComponents['site_id']->getSearchResults('Alpha'))->toBe([$firstSite->getKey() => 'Alpha Site'])
        ->and($previewComponents['preset_key']->getOptions())->toBe([]);

    $previewComponents['site_id']->state($firstSite->getKey());
    $previewComponents['page_id']->state($page->getKey());

    expect($previewComponents['page_id']->getOptions())->toHaveKey($page->getKey())
        ->and($previewComponents['page_id']->getSearchResults('Landing'))->toBe([$page->getKey() => 'Landing Page'])
        ->and($previewComponents['page_id']->getOptionLabel())->toBe('Landing Page');

    $previewComponents['site_id']->state(0);
    $previewComponents['page_id']->state(0);

    expect($previewComponents['page_id']->getOptions())->toBe([]);

    $applyComponents['scope']->state(ThemeActivationScope::SelectedSites);
    $applyComponents['site_ids']->state([$firstSite->getKey(), 'not-a-site']);

    expect($applyComponents['site_ids']->isVisible())->toBeTrue()
        ->and($applyComponents['site_ids']->isRequired())->toBeTrue()
        ->and($applyComponents['site_ids']->getOptionLabels())->toBe([$firstSite->getKey() => 'Alpha Site']);

    $applyComponents['scope']->state(ThemeActivationScope::Global->value);

    expect($applyComponents['site_ids']->isVisible())->toBeFalse();
});

/**
 * @return array<string, SchemaComponent>
 */
function themeActionSchemaComponents(Action $action, Theme $theme, ThemesTableBehaviorLivewire $livewire): array
{
    $schema = $action->getSchema(
        Schema::make($livewire)
            ->model($theme),
    );

    throw_unless($schema instanceof Schema, RuntimeException::class, 'Expected action schema.');

    return collectThemeSchemaComponents(array_values(array_filter(
        $schema->getComponents(withHidden: true),
        fn (mixed $component): bool => $component instanceof SchemaComponent,
    )))
        ->filter(fn (SchemaComponent $component): bool => method_exists($component, 'getName'))
        ->mapWithKeys(fn (SchemaComponent $component): array => [$component->getName() => $component])
        ->all();
}

/**
 * @return array<string, Action>
 */
function themeTableActionsForTest(Theme $theme, ?ThemesTableBehaviorLivewire $livewire = null): array
{
    $livewire ??= new ThemesTableBehaviorLivewire;
    $table = ThemesTable::configure(Table::make($livewire));
    new ReflectionProperty($livewire, 'table')->setValue($livewire, $table);

    return themeTableActionsFromTableForTest($table, $theme);
}

/**
 * @return array<string, Action>
 */
function themeTableActionsFromTableForTest(Table $table, Theme $theme): array
{
    $actions = [];

    foreach ($table->getRecordActions() as $action) {
        $flatActions = $action instanceof ActionGroup ? $action->getFlatActions() : [$action];

        foreach ($flatActions as $flatAction) {
            if (! $flatAction instanceof Action) {
                continue;
            }

            $actions[$flatAction->getName()] = $flatAction->record($theme);
        }
    }

    return $actions;
}

/**
 * @param  array<int, SchemaComponent>  $components
 * @return Collection<int, SchemaComponent>
 */
function collectThemeSchemaComponents(array $components): Collection
{
    return collect($components)
        ->flatMap(function (SchemaComponent $component): array {
            $children = $component->getChildSchema()?->getComponents(withHidden: true) ?? [];

            return [
                $component,
                ...collectThemeSchemaComponents(array_values(array_filter(
                    $children,
                    fn (mixed $child): bool => $child instanceof SchemaComponent,
                )))->all(),
            ];
        });
}

function stringableThemeTableValue(Htmlable|string $value): string
{
    return $value instanceof Htmlable ? $value->toHtml() : $value;
}

function registerThemesTableDefinitionForTest(Theme $theme): void
{
    $registry = app()->bound(ThemeRegistry::class)
        ? resolve(ThemeRegistry::class)
        : new ThemeRegistry;

    $registry->register(
        definition: new ThemeDefinitionData(
            key: $theme->key,
            name: $theme->name,
            description: 'Theme test definition.',
            package: 'capell-app/' . $theme->key,
            previewImage: '/themes/' . $theme->key . '.jpg',
            tags: [],
            bestFit: [],
            presets: [
                new ThemePresetData(
                    key: 'launch',
                    name: 'Launch',
                    description: 'Launch preset.',
                    previewImage: '/themes/' . $theme->key . '-launch.jpg',
                    values: [],
                ),
            ],
            includedSections: ['navigation', 'hero', 'footer'],
            assets: ['frontend' => '/themes/' . $theme->key . '.css'],
        ),
        themeRenderer: new BladeThemeRenderer($theme->key, 'missing-layout', []),
        sectionRenderers: [],
    );

    app()->instance(ThemeRegistry::class, $registry);
}
