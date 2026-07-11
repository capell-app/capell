<?php

declare(strict_types=1);

use Capell\Admin\Filament\Pages\Extensions\Tables\ExtensionsTable;
use Capell\Admin\Filament\Pages\ExtensionsPage;
use Capell\Admin\Tests\Fixtures\Autoload\AbstractPackageSettingsPageTestSchema;
use Capell\Admin\Tests\Fixtures\Autoload\AbstractPackageSettingsPageTestSettings;
use Capell\Admin\Tests\Unit\Filament\Pages\Fixtures\ExtensionTableBehaviorLivewire;
use Capell\Admin\Tests\Unit\Filament\Pages\Fixtures\ExtensionTableBehaviorLivewireWithoutGroups;
use Capell\Core\Actions\InstallPackageAction;
use Capell\Core\Data\PackageData;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Settings\SettingsGroupMetadata;
use Capell\Core\Support\Settings\SettingsSchemaRegistry;
use Filament\Actions\Action;
use Filament\Schemas\Components\Component as SchemaComponent;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Filters\BaseFilter;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Spatie\Permission\Models\Permission;

uses()->group('extension');

it('passes search filters sorting and pagination through the extensions table data source', function (): void {
    $livewire = new ExtensionTableBehaviorLivewire;
    $livewire->records = [
        ['id' => 'vendor/alpha', 'name' => 'Alpha'],
        ['id' => 'vendor/beta', 'name' => 'Beta'],
        ['id' => 'vendor/gamma', 'name' => 'Gamma'],
    ];

    $dataSource = extensionTableForBehaviorTest($livewire)->getDataSource();
    assert($dataSource instanceof Closure);

    $page = $dataSource(
        $livewire,
        ' ignored fallback ',
        [
            'extension_filters' => [
                'search' => '  Alpha  ',
                'tag' => 'Content',
                'price' => 'free',
                'health' => 'warning',
            ],
            'installed_status' => ['value' => false],
        ],
        2,
        1,
        'name',
        'desc',
    );

    $allRecordsPage = $dataSource(
        $livewire,
        ' fallback search ',
        [
            'extension_filters' => 'not-an-array',
            'installed_status' => ['value' => true],
        ],
        1,
        'all',
        'name',
        'asc',
    );

    expect($livewire->calls[0])->toMatchArray([
        'search' => 'Alpha',
        'productGroup' => 'Content',
        'filters' => [
            'price' => 'free',
            'installedStatus' => 'uninstalled',
            'health' => 'warning',
            'sort' => 'name_desc',
        ],
    ])
        ->and($page->currentPage())->toBe(2)
        ->and($page->perPage())->toBe(1)
        ->and($page->total())->toBe(3)
        ->and($page->items()[0]['id'])->toBe('vendor/beta')
        ->and($livewire->calls[1])->toMatchArray([
            'search' => 'fallback search',
            'productGroup' => null,
            'filters' => [
                'price' => null,
                'installedStatus' => 'installed',
                'health' => null,
                'sort' => 'name',
            ],
        ])
        ->and($allRecordsPage->perPage())->toBe(3);
});

it('builds extension filter indicators and product group options from table state', function (): void {
    $livewire = new ExtensionTableBehaviorLivewire;
    $livewire->tableFilters = [
        'extension_filters' => [
            'search' => '  Metrics  ',
            'tag' => 'Analytics',
            'price' => 'paid',
            'health' => 'critical',
        ],
    ];

    $table = extensionTableForBehaviorTest($livewire);
    $filter = collect($table->getFilters(withHidden: true))
        ->first(fn (BaseFilter $filter): bool => $filter->getName() === 'extension_filters');

    assert($filter instanceof BaseFilter);

    $indicators = collect($filter->getIndicators())
        ->map(function (Indicator $indicator): string {
            $label = $indicator->getLabel();

            return $label instanceof Htmlable ? $label->toHtml() : $label;
        })
        ->all();

    $tagOptionsMethod = new ReflectionMethod(ExtensionsTable::class, 'tagOptions');

    expect($indicators)->toContain(
        __('capell-admin::filter.search') . ': Metrics',
        __('capell-admin::filter.product_group') . ': Analytics',
        __('capell-admin::filter.price') . ': ' . __('capell-admin::filter.paid'),
        __('capell-admin::filter.health') . ': ' . __('capell-admin::filter.health_critical'),
    )
        ->and($tagOptionsMethod->invoke(null, $livewire))->toBe([
            'Content' => 'Content',
            'Analytics' => 'Analytics',
        ])
        ->and($tagOptionsMethod->invoke(null, new ExtensionTableBehaviorLivewireWithoutGroups))->toBe([])
        ->and($table->getEmptyStateHeading())->toBe(__('capell-admin::generic.no_extensions_available_heading'))
        ->and($table->getEmptyStateDescription())->toBe(__('capell-admin::generic.no_extensions_available_description'))
        ->and($table->getEmptyStateIcon())->toBe(Heroicon::OutlinedPuzzlePiece);
});

it('keeps extension install failures on the current table card without refreshing operations', function (): void {
    grantExtensionTableManagementAccessForBehaviorTest();

    CapellCore::registerPackage(
        name: 'vendor/installable-extension',
        path: __DIR__,
        version: '1.0.0',
    );

    $installCalls = new stdClass;
    $installCalls->count = 0;

    app()->bind(InstallPackageAction::class, fn (): object => new readonly class($installCalls)
    {
        public function __construct(private stdClass $installCalls) {}

        public function handle(PackageData $package): never
        {
            $this->installCalls->count++;

            throw new RuntimeException('Composer install failed.');
        }
    });

    $livewire = new ExtensionTableBehaviorLivewire;
    $actions = extensionTableActionsForBehaviorTest($livewire);
    $record = [
        'id' => 'vendor/installable-extension',
        'packageName' => 'vendor/installable-extension',
        'label' => 'Installable Extension',
        'installed' => false,
        'core' => false,
    ];

    $installAction = $actions['installExtension']
        ->record($record)
        ->livewire($livewire);

    expect($installAction->isVisible())->toBeTrue();

    $installAction->call([
        'record' => $record,
        'livewire' => $livewire,
    ]);

    $installAction->record([
        ...$record,
        'id' => 'vendor/missing-extension',
        'packageName' => 'vendor/missing-extension',
    ])->call([
        'record' => [
            ...$record,
            'id' => 'vendor/missing-extension',
            'packageName' => 'vendor/missing-extension',
        ],
        'livewire' => $livewire,
    ]);

    expect($installCalls->count)->toBe(1)
        ->and($livewire->rememberedPackageNames)->toBe([
            'vendor/installable-extension',
            'vendor/missing-extension',
        ])
        ->and($livewire->refreshCount)->toBe(0);
});

it('saves extension settings management surfaces through the configured table action', function (): void {
    grantExtensionTableManagementAccessForBehaviorTest();

    AbstractPackageSettingsPageTestSettings::$savedValues = [];
    AbstractPackageSettingsPageTestSettings::$persistedDefaultPayloads = [];

    app()->instance(AbstractPackageSettingsPageTestSettings::class, new AbstractPackageSettingsPageTestSettings([
        'headline' => 'Existing headline',
    ]));

    $settingsRegistry = resolve(SettingsSchemaRegistry::class);
    $settingsRegistry->registerSettingsClass('abstract-page-test', AbstractPackageSettingsPageTestSettings::class);
    $settingsRegistry->registerMetadata(new SettingsGroupMetadata(
        group: 'abstract-page-test',
        label: 'Extension settings',
        packageName: 'vendor/settings-extension',
    ));
    $settingsRegistry->register('abstract-page-test', AbstractPackageSettingsPageTestSchema::class);

    $livewire = new ExtensionTableBehaviorLivewire;
    $manageAction = extensionTableActionsForBehaviorTest($livewire)['manageExtension']
        ->livewire($livewire);
    $record = [
        'id' => 'vendor/settings-extension',
        'packageName' => 'vendor/settings-extension',
        'label' => 'Settings Extension',
        'managementSurfaces' => [
            [
                'type' => 'settings',
                'label' => 'Extension settings',
                'settingsGroup' => 'abstract-page-test',
            ],
        ],
    ];

    $schema = $manageAction
        ->record($record)
        ->getSchema(Schema::make($livewire));

    $schemaComponent = $schema?->getComponents()[0] ?? null;
    assert($schemaComponent instanceof SchemaComponent);
    assert(method_exists($schemaComponent, 'getName'));

    expect($manageAction->isVisible())->toBeTrue()
        ->and($manageAction->getModalHeading())->toBe('Extension settings')
        ->and($schemaComponent->getName())->toBe('headline');

    $manageAction->call([
        'record' => $record,
        'data' => ['headline' => 'Saved headline'],
    ]);

    $invalidSurfaceRecord = [
        'id' => 'vendor/invalid-settings-extension',
        'packageName' => 'vendor/invalid-settings-extension',
        'label' => 'Invalid Settings Extension',
        'managementSurfaces' => [
            [
                'type' => 'settings',
                'settingsGroup' => '',
            ],
        ],
    ];

    expect(AbstractPackageSettingsPageTestSettings::$savedValues)->toMatchArray([
        'headline' => 'Saved headline',
    ]);

    expect(data_get(AbstractPackageSettingsPageTestSettings::$persistedDefaultPayloads, 'abstract-page-test'))
        ->toHaveKey('fallbackHeadline', 'Fallback package headline')
        ->and($manageAction->record(['label' => 'Fallback Extension'])->getModalHeading())->toBe('Fallback Extension')
        ->and($manageAction->isVisible())->toBeFalse()
        ->and($manageAction->getSchema(Schema::make($livewire)))->toBeNull()
        ->and($manageAction->record($invalidSurfaceRecord)->getSchema(Schema::make($livewire)))->toBeNull();

    $manageAction->call([
        'record' => $invalidSurfaceRecord,
        'data' => ['headline' => 'Ignored headline'],
    ]);

    expect(AbstractPackageSettingsPageTestSettings::$savedValues)->toMatchArray([
        'headline' => 'Saved headline',
    ]);
});

function grantExtensionTableManagementAccessForBehaviorTest(): void
{
    Permission::findOrCreate('View:ExtensionsPage', 'web');
    Permission::findOrCreate(ExtensionsPage::MANAGE_PERMISSION, 'web');

    test()->actingAsAdmin();
    test()->authenticatedUser()->givePermissionTo('View:ExtensionsPage', ExtensionsPage::MANAGE_PERMISSION);
}

function extensionTableForBehaviorTest(ExtensionTableBehaviorLivewire|ExtensionTableBehaviorLivewireWithoutGroups $livewire): Table
{
    $table = ExtensionsTable::configure(Table::make($livewire));
    $livewire->mountTableForExtensionsTableBehaviorTest($table);

    return $table;
}

/**
 * @return array<string, Action>
 */
function extensionTableActionsForBehaviorTest(ExtensionTableBehaviorLivewire $livewire): array
{
    return collect(extensionTableForBehaviorTest($livewire)->getRecordActions())
        ->filter(fn (mixed $action): bool => $action instanceof Action)
        ->mapWithKeys(fn (mixed $action): array => [$action->getName() => $action])
        ->all();
}
