<?php

declare(strict_types=1);

use Capell\Admin\Actions\Themes\ResolveThemeLibraryAction;
use Capell\Admin\Contracts\Extenders\ResourceHeaderActionExtender;
use Capell\Admin\Contracts\Themes\ThemeEditorExtension;
use Capell\Admin\Data\Themes\ThemeEditorContextData;
use Capell\Admin\Data\Themes\ThemeEditorStateData;
use Capell\Admin\Enums\Themes\ThemeActivationScope;
use Capell\Admin\Filament\Components\Tables\Actions\ReplicateAction;
use Capell\Admin\Filament\Resources\Themes\Pages\ManageThemes;
use Capell\Core\Actions\CreateThemeAction;
use Capell\Core\Enums\DefaultColorEnum;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Core\ThemeStudio\Data\ThemeDefinitionData;
use Capell\Core\ThemeStudio\Data\ThemePresetData;
use Capell\Core\ThemeStudio\Discovery\LocalAppThemeDefinitionRepository;
use Capell\Core\ThemeStudio\Theme\ThemeRegistry;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\Testing\TestAction;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Filament\Tables\Columns\Column;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Pest\Expectation;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertSoftDeleted;

uses(CreatesAdminUser::class)
    ->group('theme');

function themeHeaderActionGroup(ManageThemes $page): ActionGroup
{
    $actionGroup = collect(themeHeaderActions($page))
        ->first(fn (object $action): bool => $action instanceof ActionGroup
            && ($action->getFlatActions()['createTheme'] ?? null) instanceof Action);

    throw_unless($actionGroup instanceof ActionGroup, LogicException::class, 'Theme header action group was not registered.');

    return $actionGroup;
}

/**
 * @return array<string, Action|ActionGroup>
 */
function themeHeaderActions(ManageThemes $page): array
{
    return (fn (): array => $this->getHeaderActions())->call($page);
}

/**
 * @return array<int, string>
 */
function themeHeaderActionNames(ManageThemes $page): array
{
    return collect(themeHeaderActions($page))
        ->map(function (Action|ActionGroup $action): string {
            if ($action instanceof ActionGroup) {
                return 'grouped';
            }

            $name = $action->getName();

            throw_if($name === null || $name === '', LogicException::class, 'Theme header action is missing a name.');

            return $name;
        })
        ->values()
        ->all();
}

/**
 * @param  array<int, string>  $actionNames
 */
function lastThemeHeaderActionName(array $actionNames): string
{
    $lastActionNameKey = array_key_last($actionNames);

    throw_if($lastActionNameKey === null, LogicException::class, 'Theme header action list is empty.');

    return $actionNames[$lastActionNameKey];
}

/**
 * @param  SupportCollection<int, int>  $assignedSiteIds
 */
function createThemePageScopedUser(SupportCollection $assignedSiteIds): Authenticatable
{
    $user = new class($assignedSiteIds) extends User implements FilamentUser
    {
        /** @use HasFactory<Factory<static>> */
        use HasFactory;

        /** @var SupportCollection<int, int> */
        public SupportCollection $assignedSiteIds;

        protected $table = 'users';

        /**
         * @param  SupportCollection<int, int>  $assignedSiteIds
         */
        public function __construct(?SupportCollection $assignedSiteIds = null)
        {
            parent::__construct();

            $this->assignedSiteIds = $assignedSiteIds ?? collect();
        }

        public function canAccessPanel(Panel $panel): bool
        {
            return true;
        }

        public function isGlobalAdmin(): bool
        {
            return false;
        }

        public function hasRole(string $role): bool
        {
            return false;
        }

        /**
         * @return SupportCollection<int, int>
         */
        public function getAssignedSiteIds(): SupportCollection
        {
            return $this->assignedSiteIds;
        }
    };

    $user->forceFill([
        'name' => 'Scoped Theme User',
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password'),
    ]);

    return $user;
}

beforeEach(function (): void {
    test()->actingAsAdmin();
    Blueprint::factory()->theme()->default()->create();
});

function registerManageThemesDefinition(Theme $theme): void
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
                    key: 'default',
                    name: 'Default',
                    description: 'Default preset.',
                    previewImage: '/themes/' . $theme->key . '.jpg',
                    values: [],
                ),
            ],
            includedSections: ['navigation', 'hero', 'features', 'footer'],
            assets: ['frontend' => '/themes/' . $theme->key . '.css'],
        ),
    );

    app()->instance(ThemeRegistry::class, $registry);
}

function registerAvailableManageThemesDefinition(string $themeKey = 'agency', ?string $extends = null): void
{
    $registry = app()->bound(ThemeRegistry::class)
        ? resolve(ThemeRegistry::class)
        : new ThemeRegistry;

    $registry->register(
        definition: new ThemeDefinitionData(
            key: $themeKey,
            name: Str::headline($themeKey),
            description: 'Available theme definition.',
            package: 'capell-app/theme-' . $themeKey,
            previewImage: '/themes/' . $themeKey . '.jpg',
            tags: ['Studios'],
            bestFit: ['Agencies'],
            presets: [
                new ThemePresetData(
                    key: 'launch',
                    name: 'Launch',
                    description: 'Launch preset.',
                    previewImage: '/themes/' . $themeKey . '-launch.jpg',
                    values: [],
                ),
                new ThemePresetData(
                    key: 'editorial',
                    name: 'Editorial',
                    description: 'Editorial preset.',
                    previewImage: '/themes/' . $themeKey . '-editorial.jpg',
                    values: [],
                ),
            ],
            includedSections: ['navigation', 'hero', 'features', 'proof', 'content-listing', 'cta', 'footer'],
            assets: ['frontend' => '/themes/' . $themeKey . '.css'],
            extends: $extends,
        ),
    );

    app()->instance(ThemeRegistry::class, $registry);
}

it('can list themes', function (): void {
    $themes = Theme::factory()->count(5)->create();

    Livewire::test(ManageThemes::class)
        ->assertSuccessful()
        ->assertCountTableRecords($themes->count())
        ->assertCanSeeTableRecords($themes);
});

it('theme table renders standard theme summary columns', function (): void {
    $theme = Theme::factory()->createOne([
        'key' => 'ruby',
        'name' => 'Ruby Theme',
        'admin' => [
            'editor' => [
                'description' => 'A focused editorial theme.',
            ],
        ],
        'meta' => [
            'editor' => [
                'preset' => ['active' => 'launch'],
            ],
        ],
    ]);
    registerAvailableManageThemesDefinition('ruby');

    Livewire::test(ManageThemes::class)
        ->assertSuccessful()
        ->assertSee('Ruby Theme')
        ->assertSee('A focused editorial theme.')
        ->assertSee('Launch')
        ->assertTableColumnExists('name', fn (Column $column): bool => $column->isSortable())
        ->assertTableColumnExists('editor_active_preset')
        ->assertTableColumnExists('sites_count')
        ->assertTableColumnExists('status')
        ->assertTableColumnExists('diagnostics')
        ->assertTableColumnExists('key')
        ->assertTableColumnExists('package')
        ->assertSee('capell-theme-card-record')
        ->assertTableActionDoesNotExist('viewThemeDetails', record: $theme);
});

it('theme page groups header actions for creating and installing themes', function (): void {
    $actionGroup = themeHeaderActionGroup(new ManageThemes);
    $groupedActions = $actionGroup->getFlatActions();

    expect($actionGroup->getLabel())->toBe(__('capell-admin::theme-library.actions.add_theme'))
        ->and($actionGroup->getIcon())->toBe('heroicon-o-plus')
        ->and($actionGroup->isButton())->toBeTrue()
        ->and($actionGroup->getDropdownPlacement())->toBe('bottom-end')
        ->and(array_slice(collect($groupedActions)->keys()->all(), 0, 2))->toBe(['createTheme', 'installTheme'])
        ->and($groupedActions['createTheme']->getLabel())->toBe(__('capell-admin::theme-library.actions.create_new_theme'))
        ->and($groupedActions['createTheme']->getIcon())->toBe('heroicon-o-paint-brush')
        ->and($groupedActions['installTheme']->getLabel())->toBe(__('capell-admin::theme-library.actions.install_theme'))
        ->and($groupedActions['installTheme']->getIcon())->toBe('heroicon-o-cube');

    Livewire::test(ManageThemes::class)
        ->assertSuccessful()
        ->assertActionExists('createTheme')
        ->assertActionExists('installTheme')
        ->assertActionDoesNotExist('create')
        ->assertActionDoesNotExist('create_default');
});

it('available theme definitions render with preset counts in the add theme action', function (): void {
    registerAvailableManageThemesDefinition();

    $available = ResolveThemeLibraryAction::run()['available'];

    expect($available[0]->title)->toBe('Agency')
        ->and($available[0]->presetNames)->toHaveCount(2);

    Livewire::test(ManageThemes::class)
        ->assertSuccessful()
        ->assertActionExists('installTheme')
        ->assertSee('Agency')
        ->assertDontSee(__('capell-admin::theme-library.actions.create_available'));
});

it('available theme definitions are not capped in the add theme action', function (): void {
    foreach (range(1, 6) as $index) {
        registerAvailableManageThemesDefinition('available-theme-' . $index);
    }

    $availableTitles = collect(ResolveThemeLibraryAction::run()['available'])
        ->pluck('title');

    expect($availableTitles)->toContain('Available Theme 1')
        ->and($availableTitles)->toContain('Available Theme 6');

    Livewire::test(ManageThemes::class)
        ->assertSuccessful()
        ->assertActionExists('installTheme');
});

it('can create an available theme definition with its first preset active', function (): void {
    registerAvailableManageThemesDefinition();

    Livewire::test(ManageThemes::class)
        ->assertSuccessful()
        ->callAction('installTheme', [
            'available_theme_key' => 'agency',
        ])
        ->assertHasNoErrors();

    $theme = Theme::query()->where('key', 'agency')->firstOrFail();

    expect($theme)
        ->name->toBe('Agency')
        ->default->toBeFalse()
        ->meta->editor->preset->active->toBe('launch')
        ->meta->editor->assets->paths->toBe(['/themes/agency.css']);
});

it('creating an available default-key theme does not make it default', function (): void {
    registerAvailableManageThemesDefinition('default');

    Livewire::test(ManageThemes::class)
        ->assertSuccessful()
        ->callAction('installTheme', [
            'available_theme_key' => 'default',
        ])
        ->assertHasNoErrors();

    $theme = Theme::query()->where('key', 'default')->firstOrFail();

    expect($theme)
        ->default->toBeFalse()
        ->meta->editor->preset->active->toBe('launch');
});

it('cannot create an available theme without theme create permission', function (): void {
    registerAvailableManageThemesDefinition();

    test()->actingAs(createThemePageScopedUser(collect()));

    Livewire::test(ManageThemes::class)
        ->assertSuccessful()
        ->callAction('installTheme', [
            'available_theme_key' => 'agency',
        ])
        ->assertForbidden();

    expect(Theme::query()->where('key', 'agency')->exists())->toBeFalse();
});

it('installed theme details render the active preset label', function (): void {
    $theme = Theme::factory()->createOne([
        'key' => 'agency',
        'name' => 'Agency',
        'meta' => [
            'editor' => [
                'preset' => ['active' => 'launch'],
            ],
        ],
    ]);
    registerAvailableManageThemesDefinition('agency');

    Livewire::test(ManageThemes::class)
        ->assertSuccessful()
        ->assertSee('Launch')
        ->assertDontSee('meta.active_preset');
});

it('installed theme details resolve local app definition preset labels', function (): void {
    Theme::factory()->createOne([
        'key' => 'local-agency',
        'name' => 'Local Agency',
        'meta' => [
            'editor' => [
                'preset' => ['active' => 'launch'],
            ],
        ],
    ]);

    $definition = new ThemeDefinitionData(
        key: 'local-agency',
        name: 'Local Agency',
        description: 'Local app theme definition.',
        package: 'capell-app/theme-local-agency',
        previewImage: '/themes/local-agency.jpg',
        tags: ['Studios'],
        bestFit: ['Agencies'],
        presets: [
            new ThemePresetData(
                key: 'launch',
                name: 'Local Launch',
                description: 'Local launch preset.',
                previewImage: '/themes/local-agency-launch.jpg',
                values: [],
            ),
        ],
        includedSections: ['navigation', 'hero', 'footer'],
        assets: ['frontend' => '/themes/local-agency.css'],
    );

    app()->instance(LocalAppThemeDefinitionRepository::class, new readonly class($definition)
    {
        public function __construct(private ThemeDefinitionData $definition) {}

        /** @return array<string, ThemeDefinitionData> */
        public function all(): array
        {
            return [$this->definition->key => $this->definition];
        }
    });

    Livewire::test(ManageThemes::class)
        ->assertSuccessful()
        ->assertSee('Local Launch');
});

it('cannot create the same available theme twice', function (): void {
    registerAvailableManageThemesDefinition();
    Theme::factory()->createOne(['key' => 'agency']);

    Livewire::test(ManageThemes::class)
        ->assertSuccessful()
        ->call('createAvailableTheme', 'agency')
        ->assertHasNoErrors();

    expect(Theme::query()->where('key', 'agency')->count())->toBe(1);
});

it('available theme definitions with diagnostics errors are not created', function (): void {
    registerAvailableManageThemesDefinition(extends: 'missing-parent');

    Livewire::test(ManageThemes::class)
        ->assertSuccessful()
        ->callAction('installTheme', [
            'available_theme_key' => 'agency',
        ])
        ->assertHasFormErrors(['available_theme_key']);

    expect(Theme::query()->where('key', 'agency')->exists())->toBeFalse();
});

it('theme page resolves registered header actions from optional packages', function (): void {
    app()->bind('test.theme.header.extender', fn (): ResourceHeaderActionExtender => new class implements ResourceHeaderActionExtender
    {
        public function supports(string $pageClass): bool
        {
            return $pageClass === ManageThemes::class;
        }

        public function actions(): array
        {
            return [
                Action::make('testThemeMarketplaceAction')
                    ->label('Install theme'),
            ];
        }
    });

    app()->tag(['test.theme.header.extender'], ResourceHeaderActionExtender::TAG);

    Livewire::test(ManageThemes::class)
        ->assertSuccessful()
        ->assertActionExists('testThemeMarketplaceAction');

    $actionNames = themeHeaderActionNames(new ManageThemes);

    expect($actionNames)->toContain('testThemeMarketplaceAction')
        ->and(lastThemeHeaderActionName($actionNames))->toBe('grouped')
        ->and(themeHeaderActionGroup(new ManageThemes)->getFlatActions())
        ->toHaveKeys(['createTheme', 'installTheme']);
});

it('theme page groups marketplace install action when it is registered', function (): void {
    app()->bind('test.theme.marketplace.header.extender', fn (): ResourceHeaderActionExtender => new class implements ResourceHeaderActionExtender
    {
        public function supports(string $pageClass): bool
        {
            return $pageClass === ManageThemes::class;
        }

        public function actions(): array
        {
            return [
                Action::make('installMarketplaceTheme')
                    ->label('Browse Marketplace')
                    ->icon('heroicon-o-shopping-bag'),
            ];
        }
    });

    app()->tag(['test.theme.marketplace.header.extender'], ResourceHeaderActionExtender::TAG);

    $actions = themeHeaderActions(new ManageThemes);
    $groupedActions = themeHeaderActionGroup(new ManageThemes)->getFlatActions();
    $marketplaceAction = collect($actions)
        ->first(fn (Action|ActionGroup $action): bool => $action instanceof Action
            && $action->getName() === 'installMarketplaceTheme');

    $actionNames = themeHeaderActionNames(new ManageThemes);

    throw_unless($marketplaceAction instanceof Action, LogicException::class, 'Marketplace theme action was not registered.');

    expect($actionNames[1] ?? null)->toBe('installMarketplaceTheme')
        ->and(lastThemeHeaderActionName($actionNames))->toBe('grouped')
        ->and($marketplaceAction->getLabel())->toBe('Browse Marketplace')
        ->and($marketplaceAction->getIcon())->toBe('heroicon-o-shopping-bag')
        ->and($groupedActions)->toHaveKeys(['createTheme', 'installTheme'])
        ->and($groupedActions)->not->toHaveKey('installMarketplaceTheme');
});

it('site scoped admins can open the theme page with preview action available', function (): void {
    $site = Site::factory()->withTranslations()->create();
    Page::factory()->site($site)->home()->withTranslations(slug: '/')->create();
    $theme = Theme::factory()->createOne();

    test()->actingAs(createThemePageScopedUser(collect([$site->getKey()])));

    Livewire::test(ManageThemes::class)
        ->assertSuccessful()
        ->assertTableActionVisible('previewTheme', $theme);
});

it('can filter type', function (): void {
    $type = Blueprint::factory()->theme()->create();

    Theme::factory()->state(['blueprint_id' => $type->getKey()])->create();

    $themes = Theme::factory()->count(5)->create();

    Livewire::test(ManageThemes::class)
        ->assertSuccessful()
        ->assertCountTableRecords(6)
        ->assertCanSeeTableRecords($themes)
        ->filterTable('blueprint_id', $type->getKey())
        ->assertCountTableRecords(1);
});

it('can search themes', function (): void {
    $themes = Theme::factory()
        ->sequence(fn (Sequence $sequence): array => ['name' => sprintf('Language(%d)', $sequence->index)])
        ->count(3)
        ->create();

    $name = $themes->random()->name;

    Livewire::test(ManageThemes::class)
        ->assertSuccessful()
        ->assertCountTableRecords(3)
        ->searchTable($name)
        ->assertCountTableRecords(1)
        ->assertCanSeeTableRecords($themes->where('name', $name))
        ->assertCanNotSeeTableRecords($themes->where('name', '!=', $name));
});

it('can sort themes', function (): void {
    $themes = Theme::factory()->count(10)->create();

    $sorted = Theme::query()->orderBy('name')->pluck('id');

    Livewire::test(ManageThemes::class)
        ->assertSuccessful()
        ->assertCountTableRecords($themes->count())
        ->sortTable('name')
        ->assertSuccessful()
        ->assertCanSeeTableRecords($sorted, inOrder: true);
});

it('can replicate theme', function (): void {
    $theme = Theme::factory()->createOne();

    Livewire::test(ManageThemes::class)
        ->assertSuccessful()
        ->assertCountTableRecords(1)
        ->callAction(
            TestAction::make(ReplicateAction::class)->table($theme),
            data: [
                'name' => $theme->name . ' (copy)',
                'key' => $theme->key . '-copy',
            ],
        )
        ->assertHasNoFormErrors()
        ->assertCountTableRecords(2);

    assertDatabaseHas('themes', [
        'name' => $theme->name . ' (copy)',
        'key' => $theme->key . '-copy',
    ]);
});

it('can create theme', function (): void {
    $type = Blueprint::factory()->theme()->create();
    $theme = Theme::factory()->recycle($type)->make();

    Livewire::test(ManageThemes::class)
        ->assertSuccessful()
        ->assertCountTableRecords(0)
        ->callAction('createTheme', [
            'custom_name' => $theme->name,
            'custom_key' => $theme->key,
            'custom_default' => true,
            'custom_status' => true,
            'custom_description' => 'Custom theme description.',
        ])
        ->assertHasNoFormErrors()
        ->assertCountTableRecords(1);

    assertDatabaseHas('themes', [
        'name' => $theme->name,
        'key' => $theme->key,
        'default' => 1,
    ]);

    $theme = Theme::query()->where('key', $theme->key)->firstOrFail();

    expect($theme)
        ->toBeInstanceOf(Theme::class)
        ->name->toBe($theme->name)
        ->key->toBe($theme->key)
        ->default->toBe(true)
        ->meta->editor->preset->active->toBe('default')
        ->meta->editor->header->enabled->toBe(true)
        ->admin->editor->description->toBe('Custom theme description.');
});

it('theme colors repeater tolerates null form state during action hydration', function (): void {
    $theme = Theme::factory()->make();

    Livewire::test(ManageThemes::class)
        ->assertSuccessful()
        ->callAction('createTheme', [
            'custom_name' => $theme->name,
            'custom_key' => $theme->key,
            'custom_status' => true,
        ])
        ->assertHasNoFormErrors();

    $createdTheme = Theme::query()->where('key', $theme->key)->firstOrFail();

    expect(data_get($createdTheme->meta, 'colors'))->toBeNull()
        ->and(data_get($createdTheme->meta, 'editor.brand.primaryColor'))->toBe('#0f766e');
});

it('can not create theme without required data', function (): void {
    Livewire::test(ManageThemes::class)
        ->assertSuccessful()
        ->callAction('createTheme', [
            'custom_name' => '',
            'custom_key' => '',
        ])
        ->assertHasFormErrors([
            'custom_name' => ['required'],
            'custom_key' => ['required'],
        ])
        ->assertCountTableRecords(0);
});

it('can save an admin theme description', function (): void {
    $theme = Theme::factory()->make();

    Livewire::test(ManageThemes::class)
        ->assertSuccessful()
        ->callAction('createTheme', [
            'custom_name' => $theme->name,
            'custom_key' => $theme->key,
            'custom_description' => 'A bright editorial theme for campaign landing pages.',
        ])
        ->assertHasNoFormErrors();

    $createdTheme = Theme::query()->where('key', $theme->key)->firstOrFail();

    expect(data_get($createdTheme->admin, 'editor.description'))
        ->toBe('A bright editorial theme for campaign landing pages.');
});

it('can save all theme chrome customisations', function (): void {
    $theme = Theme::factory()->make();

    Livewire::test(ManageThemes::class)
        ->assertSuccessful()
        ->callAction('createTheme', [
            'custom_name' => $theme->name,
            'custom_key' => $theme->key,
            'custom_status' => true,
        ])
        ->assertHasNoFormErrors();

    $createdTheme = Theme::query()->where('key', $theme->key)->firstOrFail();

    expect(data_get($createdTheme->meta, 'editor.preset.active'))->toBe('default')
        ->and(data_get($createdTheme->meta, 'editor.header.enabled'))->toBeTrue()
        ->and(data_get($createdTheme->meta, 'editor.footer.enabled'))->toBeTrue();
});

it('creating a custom default theme clears the previous default', function (): void {
    $existingDefault = Theme::factory()->createOne(['default' => true]);
    $theme = Theme::factory()->make();

    Livewire::test(ManageThemes::class)
        ->assertSuccessful()
        ->callAction('createTheme', [
            'custom_name' => $theme->name,
            'custom_key' => $theme->key,
            'custom_default' => true,
            'custom_status' => false,
        ])
        ->assertHasNoFormErrors();

    $createdTheme = Theme::query()->where('key', $theme->key)->firstOrFail();

    expect($createdTheme->default)->toBeTrue()
        ->and($createdTheme->status)->toBeTrue()
        ->and($existingDefault->refresh()->default)->toBeFalse()
        ->and(Theme::query()->default()->count())->toBe(1);
});

it('can edit database-backed theme fields from the admin form', function (): void {
    registerAvailableManageThemesDefinition('foundation');

    $theme = Theme::factory()->createOne([
        'name' => 'Foundation',
        'key' => 'foundation',
        'custom_css' => '.old-theme { color: #111827; }',
        'meta' => [
            'header' => true,
            'footer' => true,
            'assets' => ['resources/css/capell/frontend.css'],
            'assets_path' => 'build',
            'header_divider' => false,
            'footer_spacing' => 'compact',
            'colors' => [
                DefaultColorEnum::Black->value => '#000000',
                DefaultColorEnum::White->value => '#ffffff',
            ],
        ],
        'admin' => [
            'description' => 'Original admin card description.',
            'icon' => 'heroicon-o-swatch',
        ],
        'order' => 4,
        'default' => true,
        'status' => true,
    ]);

    Livewire::test(ManageThemes::class)
        ->assertSuccessful()
        ->mountTableAction(EditAction::class, $theme)
        ->assertMountedActionModalSee(__('capell-admin::theme-editor.help.build_path'))
        ->assertSchemaStateSet(function (array $state): array {
            expect($state['name'])->toBe('Foundation')
                ->and($state['key'])->toBe('foundation')
                ->and($state['custom_css'])->toBe('.old-theme { color: #111827; }')
                ->and($state['order'])->toBe(4.0)
                ->and((bool) $state['default'])->toBeTrue()
                ->and((bool) $state['status'])->toBeTrue()
                ->and(data_get($state, 'meta.editor.preset.active'))->toBe('launch')
                ->and(data_get($state, 'meta.editor.brand.primaryColor'))->toBe('#0f766e')
                ->and(data_get($state, 'meta.editor.assets.paths'))->toBe('/themes/foundation.css')
                ->and(data_get($state, 'admin.editor.description'))->toBe('Available theme definition.');

            return [];
        })
        ->fillForm([
            'name' => 'Foundation Edited',
            'key' => 'foundation-edited',
            'meta' => [
                'editor' => [
                    'preset' => ['active' => 'launch'],
                    'brand' => [
                        'primaryColor' => '#2563eb',
                        'accentColor' => '#f59e0b',
                        'neutralColor' => '#111827',
                    ],
                    'advanced' => [
                        'customCss' => '.edited-theme { color: #2563eb; }',
                        'mainClass' => 'theme-main',
                    ],
                    'assets' => [
                        'paths' => "resources/css/capell/frontend.css\nresources/js/capell/frontend.js",
                        'buildPath' => 'dist',
                    ],
                ],
            ],
            'admin' => [
                'editor' => [
                    'description' => 'Updated admin card description.',
                    'icon' => 'heroicon-o-paint-brush',
                ],
            ],
            'order' => 9,
            'default' => false,
            'status' => '0',
        ])
        ->callMountedAction()
        ->assertHasNoFormErrors();

    $theme->refresh();

    expect($theme)
        ->name->toBe('Foundation Edited')
        ->key->toBe('foundation-edited')
        ->order->toBe(9)
        ->default->toBeFalse()
        ->status->toBeFalse()
        ->meta->editor->preset->active->toBe('launch')
        ->meta->editor->brand->primaryColor->toBe('#2563eb')
        ->meta->editor->advanced->customCss->toBe('.edited-theme { color: #2563eb; }')
        ->meta->editor->assets->paths->toBe([
            'resources/css/capell/frontend.css',
            'resources/js/capell/frontend.js',
        ])
        ->meta->editor->assets->buildPath->toBe('dist')
        ->admin->editor->description->toBe('Updated admin card description.');
});

it('theme editor preview view passes theme context to package extensions', function (): void {
    registerManageThemesDefinition(Theme::factory()->make([
        'key' => 'package-preview',
        'name' => 'Package Preview',
    ]));

    $theme = Theme::factory()->createOne(['key' => 'package-preview']);

    app()->bind('test.real-editor-preview-extension', fn (): ThemeEditorExtension => new class implements ThemeEditorExtension
    {
        public function supports(ThemeEditorContextData $context): bool
        {
            return $context->themeKey === 'package-preview';
        }

        public function editorSections(ThemeEditorContextData $context): array
        {
            return [];
        }

        public function samplePreviewContent(ThemeEditorContextData $context): array
        {
            return ['headline' => 'Context-aware package preview'];
        }

        public function previewComponent(ThemeEditorContextData $context): ?string
        {
            return null;
        }

        public function cssVariables(ThemeEditorStateData $state, ThemeEditorContextData $context): array
        {
            return ['--package-preview-token' => '#123456'];
        }

        public function dataAttributes(ThemeEditorStateData $state, ThemeEditorContextData $context): array
        {
            return ['data-package-preview' => $context->themeKey];
        }
    });
    app()->tag(['test.real-editor-preview-extension'], ThemeEditorExtension::TAG);

    $state = [
        'meta' => ['editor' => ThemeEditorStateData::defaults()->metaEditor()],
        'admin' => ['editor' => ThemeEditorStateData::defaults()->adminEditor()],
    ];

    $html = view('capell-admin::filament.forms.theme-editor-preview', [
        'record' => $theme,
        'get' => fn (string $path): mixed => data_get($state, $path),
    ])->render();

    expect($html)->toContain('sandbox')
        ->and($html)->toContain('srcdoc')
        ->and($html)->toContain('data-preview-device="desktop"')
        ->and($html)->toContain('Context-aware package preview')
        ->and($html)->toContain('data-package-preview=&amp;quot;package-preview&amp;quot;')
        ->and($html)->toContain('--package-preview-token:#123456;');
});

it('theme table shows title and description', function (): void {
    $theme = Theme::factory()->createOne([
        'name' => 'Campaign Studio',
        'key' => 'campaign-studio',
        'admin' => [
            'editor' => [
                'description' => 'A bold theme for launch campaigns and editorial pages.',
            ],
        ],
        'default' => true,
    ]);

    Livewire::test(ManageThemes::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$theme])
        ->assertSee('Campaign Studio')
        ->assertSee('A bold theme for launch campaigns and editorial pages.');
});

it('shows a marketplace install empty state when no themes exist', function (): void {
    Livewire::test(ManageThemes::class)
        ->assertSuccessful()
        ->assertSeeText(__('capell-admin::table.theme_empty_heading'))
        ->assertSeeText(__('capell-admin::table.theme_empty_description'));
});

it('can apply a theme globally from the theme table actions', function (): void {
    $activeTheme = Theme::factory()->createOne(['default' => true]);
    $selectedTheme = Theme::factory()->createOne(['default' => false]);
    registerManageThemesDefinition($selectedTheme);

    Livewire::test(ManageThemes::class)
        ->assertSuccessful()
        ->callAction(
            TestAction::make('applyTheme')->table($selectedTheme),
            data: [
                'scope' => ThemeActivationScope::Global->value,
                'site_ids' => [],
            ],
        )
        ->assertHasNoFormErrors();

    expect($selectedTheme->refresh()->default)->toBeTrue()
        ->and($activeTheme->refresh()->default)->toBeFalse();
});

it('cannot apply a theme with diagnostics errors', function (): void {
    $theme = Theme::factory()->createOne([
        'key' => 'missing-diagnostics-theme',
        'default' => false,
    ]);

    Livewire::test(ManageThemes::class)
        ->assertSuccessful()
        ->assertSee(__('capell-admin::theme-library.labels.diagnostics_error'))
        ->assertTableActionDisabled('applyTheme', $theme);
});

it('diagnostics badge opens the diagnostics modal', function (): void {
    $theme = Theme::factory()->createOne([
        'key' => 'missing-diagnostics-theme',
        'name' => 'Missing Diagnostics Theme',
        'default' => false,
    ]);

    Livewire::test(ManageThemes::class)
        ->assertSuccessful()
        ->assertTableActionExists('viewThemeDiagnostics', record: $theme)
        ->mountTableAction('viewThemeDiagnostics', $theme)
        ->assertMountedActionModalSee(__('capell-admin::theme-library.diagnostics.missing_definition'));
});

it('can apply a theme to selected sites from the theme table actions', function (): void {
    $globalTheme = Theme::factory()->createOne(['default' => true]);
    $selectedTheme = Theme::factory()->createOne(['default' => false]);
    $unchangedTheme = Theme::factory()->createOne(['default' => false]);
    registerManageThemesDefinition($selectedTheme);

    $selectedSite = Site::factory()->theme($globalTheme)->create();
    $anotherSelectedSite = Site::factory()->theme($globalTheme)->create();
    $unselectedSite = Site::factory()->theme($unchangedTheme)->create();

    Livewire::test(ManageThemes::class)
        ->assertSuccessful()
        ->callAction(
            TestAction::make('applyTheme')->table($selectedTheme),
            data: [
                'scope' => ThemeActivationScope::SelectedSites->value,
                'site_ids' => [
                    $selectedSite->getKey(),
                    $anotherSelectedSite->getKey(),
                ],
            ],
        )
        ->assertHasNoFormErrors();

    expect($selectedTheme->refresh()->default)->toBeFalse()
        ->and($globalTheme->refresh()->default)->toBeTrue()
        ->and($selectedSite->refresh()->theme_id)->toBe($selectedTheme->getKey())
        ->and($anotherSelectedSite->refresh()->theme_id)->toBe($selectedTheme->getKey())
        ->and($unselectedSite->refresh()->theme_id)->toBe($unchangedTheme->getKey());
});

it('site scoped admins cannot tamper theme apply scope or site ids', function (): void {
    $globalTheme = Theme::factory()->createOne(['default' => true]);
    $selectedTheme = Theme::factory()->createOne(['default' => false]);
    registerManageThemesDefinition($selectedTheme);
    $assignedSite = Site::factory()->theme($globalTheme)->create();
    $unassignedSite = Site::factory()->theme($globalTheme)->create();

    test()->actingAs(createThemePageScopedUser(collect([$assignedSite->getKey()])));

    Livewire::test(ManageThemes::class)
        ->assertSuccessful()
        ->callAction(
            TestAction::make('applyTheme')->table($selectedTheme),
            data: [
                'scope' => ThemeActivationScope::Global->value,
                'site_ids' => [],
            ],
        )
        ->assertHasFormErrors(['scope']);

    Livewire::test(ManageThemes::class)
        ->assertSuccessful()
        ->callAction(
            TestAction::make('applyTheme')->table($selectedTheme),
            data: [
                'scope' => ThemeActivationScope::SelectedSites->value,
                'site_ids' => [$unassignedSite->getKey()],
            ],
        );

    expect($selectedTheme->refresh()->default)->toBeFalse()
        ->and($assignedSite->refresh()->theme_id)->toBe($globalTheme->getKey())
        ->and($unassignedSite->refresh()->theme_id)->toBe($globalTheme->getKey());
});

it('selected site theme apply requires at least one site', function (): void {
    $selectedTheme = Theme::factory()->createOne(['default' => false]);
    registerManageThemesDefinition($selectedTheme);

    Livewire::test(ManageThemes::class)
        ->assertSuccessful()
        ->callAction(
            TestAction::make('applyTheme')->table($selectedTheme),
            data: [
                'scope' => ThemeActivationScope::SelectedSites->value,
                'site_ids' => [],
            ],
        )
        ->assertHasFormErrors(['site_ids']);
});

it('can save default theme without loosing data', function (): void {
    $defaultTheme = CreateThemeAction::run();
    assert($defaultTheme->blueprint instanceof Blueprint);

    Livewire::test(ManageThemes::class)
        ->assertSuccessful()
        ->callAction(TestAction::make(EditAction::class)->table($defaultTheme))
        ->assertHasNoFormErrors();

    $theme = Theme::query()->default()->first();

    expect($theme)
        ->name->toBe($defaultTheme->name)
        ->key->toBe($defaultTheme->key)
        ->default->toBeTrue()
        ->blueprint->scoped(
            function (Expectation $type) use ($defaultTheme): void {
                $type
                    ->toBeInstanceOf(Blueprint::class)
                    ->id->toBe($defaultTheme->blueprint->id);
            },
        )
        ->meta->scoped(
            function (Expectation $meta): void {
                $meta
                    ->editor->preset->active->toBe('default')
                    ->editor->header->enabled->toBeTrue()
                    ->editor->footer->enabled->toBeTrue();
            },
        );
});

it('can delete theme', function (): void {
    $theme = Theme::factory()->createOne();

    Livewire::test(ManageThemes::class)
        ->assertSuccessful()
        ->assertCountTableRecords(1)
        ->callAction(TestAction::make(DeleteAction::class)->table($theme))
        ->assertHasNoFormErrors()
        ->assertCountTableRecords(0);

    assertSoftDeleted($theme, ['id' => $theme->id]);
});

it('can group delete themes', function (): void {
    $themes = Theme::factory()->count(5)->create();

    Livewire::test(ManageThemes::class)
        ->assertSuccessful()
        ->selectTableRecords($themes)
        ->callAction(TestAction::make(DeleteBulkAction::class)->table()->bulk())
        ->assertHasNoFormErrors();

    foreach ($themes as $theme) {
        assertSoftDeleted($theme, ['id' => $theme->id]);
    }
});

it('can not delete theme if it is used', function (): void {
    $theme = Theme::factory()->createOne();
    Site::factory()->theme($theme)->create();

    Livewire::test(ManageThemes::class)
        ->assertSuccessful()
        ->assertCountTableRecords(1)
        ->callAction(TestAction::make(DeleteAction::class)->table($theme))
        ->assertNotified(__(
            'capell-admin::message.theme_not_deletable',
            ['name' => $theme->name],
        ))
        ->assertCountTableRecords(1);

    assertDatabaseHas($theme, ['id' => $theme->id]);
});
