<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Pages\Extensions\Tables;

use Capell\Admin\Actions\BuildSettingsSchemaComponentsAction;
use Capell\Admin\Actions\PersistMissingSettingsDefaultsAction;
use Capell\Admin\Contracts\Extensions\ExtensionTableDataSource;
use Capell\Admin\Enums\ExtensionHealthFilterEnum;
use Capell\Admin\Enums\ExtensionPriceFilterEnum;
use Capell\Admin\Filament\Components\Tables\Columns\IdentifierColumn;
use Capell\Admin\Filament\Contracts\TableConfigurator;
use Capell\Admin\Filament\Pages\Extensions\Tables\Actions\DeleteExtensionAction;
use Capell\Admin\Filament\Pages\Extensions\Tables\Actions\EnableExtensionAction;
use Capell\Admin\Filament\Pages\Extensions\Tables\Actions\InstallExtensionAction;
use Capell\Admin\Filament\Pages\Extensions\Tables\Actions\UninstallExtensionAction;
use Capell\Admin\Filament\Pages\ExtensionsPage;
use Capell\Admin\Support\Extensions\ExtensionsPageActionRegistry;
use Capell\Core\Contracts\SettingsContract;
use Capell\Core\Support\Settings\SettingsSchemaRegistry;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Layout\View as LayoutView;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use LogicException;

class ExtensionsTable implements TableConfigurator
{
    public static function configure(Table $table): Table
    {
        $livewire = $table->getLivewire();

        throw_unless($livewire instanceof ExtensionTableDataSource, LogicException::class, 'ExtensionsTable must be configured by an extension table data source.');

        return $table
            ->records(fn (ExtensionTableDataSource $livewire, ?string $search, ?array $filters, int|string $page, int|string|null $recordsPerPage, ?string $sortColumn, ?string $sortDirection): LengthAwarePaginator => self::paginateRecords(
                records: $livewire->getExtensionsData(
                    self::selectedSearchFilter($search, $filters),
                    self::selectedTagFilter($filters),
                    self::selectedAdvancedFilters($filters, $sortColumn, $sortDirection),
                ),
                page: $page,
                recordsPerPage: $recordsPerPage,
            ))
            ->defaultSortOptionLabel(__('capell-admin::filter.sort_latest_activity'))
            ->contentGrid([
                'md' => 2,
                'xl' => 3,
            ])
            ->recordClasses('capell-extension-card-record')
            ->columns(static::getTableColumns())
            ->filters([
                Filter::make('extension_filters')
                    ->label(__('capell-admin::filter.extensions'))
                    ->schema([
                        TextInput::make('search')
                            ->label(__('capell-admin::filter.search'))
                            ->placeholder(__('capell-admin::filter.search_extensions')),
                        Select::make('tag')
                            ->label(__('capell-admin::filter.product_group'))
                            ->placeholder(__('filament-tables::table.filters.select.placeholder'))
                            ->options(fn (): array => self::tagOptions($livewire)),
                        Select::make('price')
                            ->label(__('capell-admin::filter.price'))
                            ->placeholder(__('capell-admin::filter.any_price'))
                            ->options(self::priceOptions()),
                        Select::make('health')
                            ->label(__('capell-admin::filter.health'))
                            ->placeholder(__('capell-admin::filter.any_health'))
                            ->options(self::healthOptions()),
                    ])
                    ->columnSpanFull()
                    ->columns([
                        'md' => 2,
                        'xl' => 3,
                    ])
                    ->indicateUsing(fn (array $data): array => self::filterIndicators($data)),
                TernaryFilter::make('installed_status')
                    ->label(__('capell-admin::filter.installed_status'))
                    ->placeholder(__('capell-admin::filter.extensions_all'))
                    ->trueLabel(__('capell-admin::filter.extensions_installed'))
                    ->falseLabel(__('capell-admin::filter.extensions_uninstalled')),
            ])
            ->filtersFormColumns([
                'md' => 2,
                'xl' => 3,
            ])
            ->filtersFormWidth(Width::FourExtraLarge)
            ->deferFilters(false)
            ->emptyStateHeading(__('capell-admin::generic.no_extensions_available_heading'))
            ->emptyStateDescription(__('capell-admin::generic.no_extensions_available_description'))
            ->emptyStateIcon(Heroicon::OutlinedPuzzlePiece)
            ->emptyStateActions(resolve(ExtensionsPageActionRegistry::class)->tableActions(resolve(ExtensionsPage::class)))
            ->selectable(false)
            ->columnManager(false)
            ->defaultPaginationPageOption(12)
            ->paginationPageOptions([12, 24, 48, 'all'])
            ->recordActions(self::defaultTableActions(), RecordActionsPosition::AfterCells);
    }

    /** @return array<int, mixed> */
    protected static function getTableColumns(): array
    {
        /** @var view-string $extensionCardView */
        $extensionCardView = 'capell-admin::filament.pages.extensions.extension-card';

        return [
            TextColumn::make('name')
                ->label(__('capell-admin::filter.sort_name'))
                ->sortable()
                ->extraAttributes(['style' => 'display: none']),
            IdentifierColumn::make('id')
                ->hidden(),
            LayoutView::make($extensionCardView),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    private static function paginateRecords(array $records, int|string $page, int|string|null $recordsPerPage): LengthAwarePaginator
    {
        $collection = collect($records);
        $total = $collection->count();
        $perPage = $recordsPerPage === 'all' || $recordsPerPage === null ? $total : (int) $recordsPerPage;
        $perPage = max(1, $perPage);

        $currentPage = max(1, (int) $page);

        return new LengthAwarePaginator(
            items: $collection->forPage($currentPage, $perPage)->values(),
            total: $total,
            perPage: $perPage,
            currentPage: $currentPage,
            options: [
                'pageName' => 'installed-extensionsPage',
                'path' => request()->url(),
            ],
        );
    }

    /**
     * @param  array<string, mixed>|null  $filters
     */
    private static function selectedSearchFilter(?string $search, ?array $filters): ?string
    {
        $value = $filters['extension_filters']['search'] ?? $search;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @param  array<string, mixed>|null  $filters
     */
    private static function selectedTagFilter(?array $filters): ?string
    {
        $value = $filters['extension_filters']['tag'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  array<string, mixed>|null  $filters
     * @return array<string, string|null>
     */
    private static function selectedAdvancedFilters(?array $filters, ?string $sortColumn, ?string $sortDirection): array
    {
        $data = is_array($filters['extension_filters'] ?? null)
            ? $filters['extension_filters']
            : [];

        return [
            'price' => self::selectedOption($data['price'] ?? null),
            'installedStatus' => self::selectedInstalledStatus($filters['installed_status']['value'] ?? null),
            'health' => self::selectedOption($data['health'] ?? null),
            'sort' => self::selectedSort($sortColumn, $sortDirection),
        ];
    }

    private static function selectedSort(?string $sortColumn, ?string $sortDirection): string
    {
        if ($sortColumn !== 'name') {
            return 'latest';
        }

        return $sortDirection === 'desc' ? 'name_desc' : 'name';
    }

    private static function selectedOption(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function selectedInstalledStatus(mixed $value): string
    {
        return match ($value) {
            true => 'installed',
            false => 'uninstalled',
            default => 'all',
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    private static function filterIndicators(array $data): array
    {
        return collect([
            'search' => is_string($data['search'] ?? null) && trim($data['search']) !== ''
                ? __('capell-admin::filter.search') . ': ' . trim($data['search'])
                : null,
            'tag' => is_string($data['tag'] ?? null) && $data['tag'] !== ''
                ? __('capell-admin::filter.product_group') . ': ' . $data['tag']
                : null,
            'price' => self::filterIndicator(__('capell-admin::filter.price'), self::priceOptions(), $data['price'] ?? null),
            'health' => self::filterIndicator(__('capell-admin::filter.health'), self::healthOptions(), $data['health'] ?? null),
        ])->filter()->values()->all();
    }

    /**
     * @param  array<string, string>  $options
     */
    private static function filterIndicator(string $label, array $options, mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return isset($options[$value])
            ? $label . ': ' . $options[$value]
            : null;
    }

    /** @return array<string, string> */
    private static function priceOptions(): array
    {
        return ExtensionPriceFilterEnum::options();
    }

    /** @return array<string, string> */
    private static function healthOptions(): array
    {
        return ExtensionHealthFilterEnum::options();
    }

    /** @return array<string, string> */
    private static function tagOptions(ExtensionTableDataSource $livewire): array
    {
        if (! method_exists($livewire, 'getProductGroups')) {
            return [];
        }

        /** @var list<string> $groups */
        $groups = $livewire->getProductGroups();

        return collect($groups)
            ->mapWithKeys(fn (string $group): array => [$group => $group])
            ->all();
    }

    /** @return array<int, mixed> */
    private static function defaultTableActions(): array
    {
        return [
            Action::make('viewExtensionDetails')
                ->label(__('capell-admin::button.view_details'))
                ->icon(Heroicon::OutlinedQuestionMarkCircle)
                ->color('gray')
                ->extraAttributes(['class' => 'capell-extension-card-details-action'])
                ->slideOver()
                ->modalSubmitAction(false)
                ->modalCancelActionLabel(__('capell-admin::button.close'))
                ->modalHeading(fn (array $record): string => (string) ($record['label'] ?? $record['packageName'] ?? __('capell-admin::button.view_details')))
                ->modalContent(fn (array $record): View => view(
                    'capell-admin::filament.pages.extensions.extension-details',
                    ['record' => $record],
                )),
            Action::make('manageExtension')
                ->label(__('capell-admin::button.edit'))
                ->tooltip(__('capell-admin::button.manage_extension'))
                ->icon(Heroicon::OutlinedPencilSquare)
                ->color('gray')
                ->button()
                ->slideOver()
                ->modalCancelActionLabel(__('capell-admin::button.close'))
                ->modalWidth(Width::ScreenLarge)
                ->modalHeading(fn (?array $record): string => self::managementHeading($record ?? []))
                ->schema(fn (?array $record, Schema $schema): array => self::managementSchema($record ?? [], $schema))
                ->fillForm(fn (?array $record): array => self::managementFormData($record ?? []))
                ->action(function (array $record, array $data): void {
                    self::saveManagementSurface($record, $data);
                })
                ->visible(fn (array $record): bool => self::getPrimaryManagementSurface($record) !== null),
            Action::make('openExtension')
                ->label(__('capell-admin::button.edit'))
                ->icon(Heroicon::OutlinedPencilSquare)
                ->color('gray')
                ->button()
                ->url(fn (array $record): ?string => self::getPrimaryUrl($record))
                ->visible(fn (array $record): bool => self::getPrimaryManagementSurface($record) === null
                    && self::getPrimaryUrl($record) !== null),
            InstallExtensionAction::make(),
            EnableExtensionAction::make(),
            UninstallExtensionAction::make(),
            DeleteExtensionAction::make(),
        ];
    }

    /** @param array<string, mixed> $record */
    private static function getPrimaryUrl(array $record): ?string
    {
        $url = $record['primaryUrl'] ?? null;

        return is_string($url) && $url !== '' ? $url : null;
    }

    /** @param array<string, mixed> $record */
    private static function managementHeading(array $record): string
    {
        $surface = self::getPrimaryManagementSurface($record);

        if ($surface !== null && is_string($surface['label'] ?? null) && $surface['label'] !== '') {
            return $surface['label'];
        }

        return (string) ($record['label'] ?? $record['packageName'] ?? __('capell-admin::button.manage_extension'));
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>|null
     */
    private static function getPrimaryManagementSurface(array $record): ?array
    {
        $surfaces = $record['managementSurfaces'] ?? null;

        if (! is_array($surfaces)) {
            return null;
        }

        $surface = $surfaces[0] ?? null;

        return is_array($surface) ? $surface : null;
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<int, mixed>
     */
    private static function managementSchema(array $record, Schema $schema): array
    {
        $surface = self::getPrimaryManagementSurface($record);

        if (($surface['type'] ?? null) !== 'settings') {
            return [];
        }

        $settingsGroup = $surface['settingsGroup'] ?? null;

        if (! is_string($settingsGroup) || $settingsGroup === '') {
            return [];
        }

        return collect(resolve(SettingsSchemaRegistry::class)->getSchemas($settingsGroup))
            ->flatMap(fn (string $schemaClass): array => BuildSettingsSchemaComponentsAction::run($schemaClass, $schema))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private static function managementFormData(array $record): array
    {
        $settingsClass = self::managementSettingsClass($record);

        if ($settingsClass === null) {
            return [];
        }

        return resolve($settingsClass)->toArray();
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $data
     */
    private static function saveManagementSurface(array $record, array $data): void
    {
        $settingsClass = self::managementSettingsClass($record);

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
     * @param  array<string, mixed>  $record
     * @return class-string<SettingsContract>|null
     */
    private static function managementSettingsClass(array $record): ?string
    {
        $surface = self::getPrimaryManagementSurface($record);

        if (($surface['type'] ?? null) !== 'settings') {
            return null;
        }

        $settingsGroup = $surface['settingsGroup'] ?? null;

        if (! is_string($settingsGroup) || $settingsGroup === '') {
            return null;
        }

        $settingsClass = resolve(SettingsSchemaRegistry::class)->getSettingsClass($settingsGroup);

        return is_string($settingsClass) && is_a($settingsClass, SettingsContract::class, true)
            ? $settingsClass
            : null;
    }
}
