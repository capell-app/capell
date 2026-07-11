<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Pages;

use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Capell\Admin\Actions\Extensions\BuildExtensionOperationsSummaryAction;
use Capell\Admin\Actions\Extensions\FilterExtensionManagementEntriesAction;
use Capell\Admin\Actions\ListExtensionManagementEntriesAction;
use Capell\Admin\Actions\PersistMissingSettingsDefaultsAction;
use Capell\Admin\Actions\SyncDashboardFilamentWidgetSettingsAction;
use Capell\Admin\Contracts\Extenders\ExtensionsPageExtender;
use Capell\Admin\Contracts\Extensions\ExtensionTableDataSource;
use Capell\Admin\Data\ExtensionManagementEntryData;
use Capell\Admin\Data\Extensions\ExtensionOperationPackageData;
use Capell\Admin\Data\Extensions\ExtensionOperationsSummaryData;
use Capell\Admin\Filament\Concerns\CustomisesExtensionsDashboard;
use Capell\Admin\Filament\Pages\Extensions\Concerns\PreservesExtensionTablePosition;
use Capell\Admin\Filament\Pages\Extensions\Tables\ExtensionsTable;
use Capell\Admin\Filament\Widgets\Extensions\ExtensionStatsOverviewFilamentWidget;
use Capell\Admin\Settings\AdminSettings;
use Capell\Admin\Support\Extensions\ExtensionsPageActionRegistry;
use Capell\Core\Contracts\SettingsContract;
use Capell\Core\Support\Settings\SettingsSchemaRegistry;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\Widget;
use Filament\Widgets\WidgetConfiguration;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Url;
use Override;

class ExtensionsPage extends Dashboard implements ExtensionTableDataSource, HasActions, HasTable
{
    use CustomisesExtensionsDashboard;
    use HasPageShield;
    use InteractsWithActions;
    use InteractsWithTable;
    use PreservesExtensionTablePosition;

    public const string MANAGE_PERMISSION = 'Manage:ExtensionsPage';

    public ?string $activeProductGroup = null;

    #[Url]
    public ?string $manage = null;

    #[Url]
    public ?string $surface = null;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPuzzlePiece;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::PuzzlePiece;

    protected static ?string $slug = 'extensions';

    protected static bool $shouldRegisterNavigation = true;

    protected static ?int $navigationSort = PHP_INT_MIN;

    protected string $view = 'capell-admin::filament.pages.extensions';

    private ?ExtensionOperationsSummaryData $operationsSummary = null;

    #[Override]
    public static function getNavigationLabel(): string
    {
        return (string) (__('capell-admin::navigation.extensions'));
    }

    #[Override]
    public static function getNavigationGroup(): ?string
    {
        return (string) (__('capell-admin::navigation.group_system'));
    }

    #[Override]
    public static function getRoutePath(Panel $panel): string
    {
        return '/' . static::getSlug($panel);
    }

    public static function canManageExtensions(): bool
    {
        return auth()->user()?->can(self::MANAGE_PERMISSION) ?? false;
    }

    #[Override]
    public static function getNavigationBadge(): ?string
    {
        $count = BuildExtensionOperationsSummaryAction::run()->unhealthyCount;

        if ($count === 0) {
            return null;
        }

        return number_format($count);
    }

    #[Override]
    public static function getNavigationBadgeColor(): string|array|null
    {
        return BuildExtensionOperationsSummaryAction::run()->blockedCount > 0 ? 'danger' : 'warning';
    }

    public function mount(): void
    {
        $packageName = $this->manage ?? request()->query('manage');
        $surface = $this->surface ?? request()->query('surface');

        if (! is_string($packageName) || ! is_string($surface) || ! $this->hasManagementSurface($packageName, $surface)) {
            return;
        }

        $this->mountAction('manageExtension', [
            'packageName' => $packageName,
            'surface' => $surface,
        ]);
    }

    public function extensionTableSearchTerm(): ?string
    {
        $filterSearch = $this->tableFilters['extension_filters']['search'] ?? null;

        if (is_string($filterSearch) && trim($filterSearch) !== '') {
            return trim($filterSearch);
        }

        $tableSearch = $this->tableSearch ?? null;

        return is_string($tableSearch) && trim($tableSearch) !== '' ? trim($tableSearch) : null;
    }

    #[Override]
    public function getTitle(): string|Htmlable
    {
        return __('capell-admin::generic.extensions');
    }

    #[Override]
    public function getSubheading(): string|Htmlable|null
    {
        return __('capell-admin::generic.extensions_info');
    }

    public function getOperationsSummary(): ExtensionOperationsSummaryData
    {
        return $this->operationsSummary ??= BuildExtensionOperationsSummaryAction::run();
    }

    #[Override]
    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE),
                EmbeddedTable::make(),
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER),
            ]);
    }

    public function table(Table $table): Table
    {
        return ExtensionsTable::configure($table)
            ->heading(null)
            ->description(null)
            ->queryStringIdentifier('installed-extensions');
    }

    #[Override]
    public function getColumns(): array
    {
        return ['default' => 1, '@3xl' => 12, '!@lg' => 12];
    }

    #[Override]
    public function getHeaderWidgetsColumns(): array
    {
        return $this->getColumns();
    }

    /**
     * @return array<class-string<Widget>|WidgetConfiguration>
     */
    #[Override]
    public function getWidgets(): array
    {
        return [];
    }

    /**
     * @param  array<string, bool|string|null>  $filters
     * @return list<array<string, mixed>>
     */
    public function getExtensionsData(?string $search = null, ?string $productGroup = null, array $filters = []): array
    {
        /** @var list<ExtensionManagementEntryData> $entries */
        $entries = FilterExtensionManagementEntriesAction::run(
            entries: ListExtensionManagementEntriesAction::run(),
            search: $search,
            productGroup: $productGroup ?? $this->activeProductGroup,
            installedStatus: $filters['installedStatus'] ?? 'all',
            price: $filters['price'] ?? null,
            health: $filters['health'] ?? null,
            sort: $filters['sort'] ?? 'latest',
        );

        $records = array_values(collect($entries)
            ->map(fn (ExtensionManagementEntryData $entry): array => $entry->toTableRecord())
            ->values()
            ->all());

        return $this->applyPinnedExtensionTablePosition($records);
    }

    public function refreshExtensionOperations(): void
    {
        BuildExtensionOperationsSummaryAction::forgetRequestCache();
        ListExtensionManagementEntriesAction::forgetRequestCache();

        $this->operationsSummary = null;
        $this->resetTable();
    }

    /** @return list<string> */
    public function getProductGroups(): array
    {
        return array_values(collect($this->getOperationsSummary()->packages)
            ->map(fn (ExtensionOperationPackageData $package): string => $package->productGroup)
            ->unique()
            ->sort()
            ->values()
            ->all());
    }

    /** @return array<int, Htmlable|string> */
    public function getBeforeTableContent(): array
    {
        return collect(app()->tagged(ExtensionsPageExtender::TAG))
            ->flatMap(fn (ExtensionsPageExtender $extender): array => $extender->getBeforeTableContent($this))
            ->values()
            ->all();
    }

    public function manageExtensionAction(): Action
    {
        return Action::make('manageExtension')
            ->label(__('capell-admin::button.manage_extension'))
            ->icon(Heroicon::OutlinedCog6Tooth)
            ->slideOver()
            ->modalWidth(Width::ScreenLarge)
            ->modalCancelActionLabel(__('capell-admin::button.close'))
            ->modalHeading(fn (Action $action): string => $this->managementHeading($action))
            ->schema(fn (Schema $schema, Action $action): array => $this->managementSchema($schema, $action))
            ->fillForm(fn (Action $action): array => $this->managementFormData($action))
            ->action(function (array $data, Action $action): void {
                $this->saveManagementSurface($data, $action);
            });
    }

    /**
     * @return array<class-string<Widget>|WidgetConfiguration>
     */
    #[Override]
    protected function getHeaderWidgets(): array
    {
        SyncDashboardFilamentWidgetSettingsAction::run();

        return $this->configuredDashboardFilamentWidgets([
            ExtensionStatsOverviewFilamentWidget::class,
        ]);
    }

    /**
     * @return array<int, Action|ActionGroup>
     */
    #[Override]
    protected function getHeaderActions(): array
    {
        $actionRegistry = resolve(ExtensionsPageActionRegistry::class);

        return [
            ...$actionRegistry->headerActions($this),
            ActionGroup::make([
                ...$actionRegistry->headerActionGroupActions($this),
                $this->customiseExtensionsDashboardAction(),
            ])
                ->icon(Heroicon::OutlinedEllipsisVertical)
                ->tooltip(__('capell-admin::generic.actions'))
                ->color('gray'),
        ];
    }

    /**
     * @param  list<class-string<Widget>>  $widgets
     * @return list<class-string<Widget>|WidgetConfiguration>
     */
    private function configuredDashboardFilamentWidgets(array $widgets): array
    {
        $settings = resolve(AdminSettings::class);

        return array_values(collect($widgets)
            ->filter(function (string $widgetClass) use ($settings): bool {
                if (! method_exists($widgetClass, 'settingsKey')) {
                    return true;
                }

                $settingsKey = $widgetClass::settingsKey();

                return ! is_string($settingsKey)
                    || $settingsKey === ''
                    || $settings->isWidgetEnabled($settingsKey);
            })
            ->sortBy(fn (string $widgetClass, int $index): string => sprintf(
                '%020d-%020d',
                $this->widgetSortOrder($settings, $widgetClass),
                $index,
            ))
            ->values()
            ->all());
    }

    private function widgetSortOrder(AdminSettings $settings, string $widgetClass): int
    {
        if (! method_exists($widgetClass, 'settingsKey')) {
            return 999;
        }

        $settingsKey = $widgetClass::settingsKey();

        if (! is_string($settingsKey) || $settingsKey === '') {
            return 999;
        }

        return $settings->widget_order[$settingsKey]
            ?? AdminSettings::defaultWidgetOrder()[$settingsKey]
            ?? 999;
    }

    private function hasManagementSurface(string $packageName, string $settingsGroup): bool
    {
        return $this->managementSurfaceRecord($packageName, $settingsGroup) !== null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function managementSurfaceRecord(string $packageName, string $settingsGroup): ?array
    {
        $record = collect($this->getExtensionsData())
            ->firstWhere('packageName', $packageName);

        if (! is_array($record)) {
            return null;
        }

        $surface = collect(is_array($record['managementSurfaces'] ?? null) ? $record['managementSurfaces'] : [])
            ->first(fn (mixed $surface): bool => is_array($surface)
                && ($surface['type'] ?? null) === 'settings'
                && ($surface['settingsGroup'] ?? null) === $settingsGroup);

        return is_array($surface) ? $surface : null;
    }

    private function managementHeading(Action $action): string
    {
        $surface = $this->surfaceFromAction($action);

        return is_string($surface['label'] ?? null) && $surface['label'] !== ''
            ? $surface['label']
            : __('capell-admin::button.manage_extension');
    }

    /**
     * @return array<int, mixed>
     */
    private function managementSchema(Schema $schema, Action $action): array
    {
        $settingsGroup = $this->settingsGroupFromAction($action);

        if ($settingsGroup === null) {
            return [];
        }

        return collect(resolve(SettingsSchemaRegistry::class)->getSchemas($settingsGroup))
            ->flatMap(fn (string $schemaClass): array => $schemaClass::make($schema))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function managementFormData(Action $action): array
    {
        $settingsClass = $this->managementSettingsClass($action);

        if ($settingsClass === null) {
            return [];
        }

        return resolve($settingsClass)->toArray();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function saveManagementSurface(array $data, Action $action): void
    {
        $settingsClass = $this->managementSettingsClass($action);

        if ($settingsClass === null) {
            return;
        }

        PersistMissingSettingsDefaultsAction::run($settingsClass);

        $settings = resolve($settingsClass);
        $settings->fill($data);
        $settings->save();

        Notification::make('extension-management-saved')
            ->title(__('capell-admin::message.settings_saved'))
            ->success()
            ->send();
    }

    /**
     * @return class-string<SettingsContract>|null
     */
    private function managementSettingsClass(Action $action): ?string
    {
        $settingsGroup = $this->settingsGroupFromAction($action);

        if ($settingsGroup === null) {
            return null;
        }

        $settingsClass = resolve(SettingsSchemaRegistry::class)->getSettingsClass($settingsGroup);

        return is_string($settingsClass) && is_a($settingsClass, SettingsContract::class, true)
            ? $settingsClass
            : null;
    }

    private function settingsGroupFromAction(Action $action): ?string
    {
        $surface = $this->surfaceFromAction($action);
        $settingsGroup = $surface['settingsGroup'] ?? null;

        return is_string($settingsGroup) && $settingsGroup !== '' ? $settingsGroup : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function surfaceFromAction(Action $action): array
    {
        $arguments = $action->getArguments();
        $packageName = $arguments['packageName'] ?? null;
        $surface = $arguments['surface'] ?? null;

        if (! is_string($packageName) || ! is_string($surface)) {
            return [];
        }

        return $this->managementSurfaceRecord($packageName, $surface) ?? [];
    }
}
