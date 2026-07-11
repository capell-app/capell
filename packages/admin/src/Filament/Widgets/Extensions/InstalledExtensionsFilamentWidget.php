<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Widgets\Extensions;

use Capell\Admin\Actions\Extensions\BuildExtensionOperationsSummaryAction;
use Capell\Admin\Actions\Extensions\FilterExtensionManagementEntriesAction;
use Capell\Admin\Actions\ListExtensionManagementEntriesAction;
use Capell\Admin\Contracts\CapellFilamentWidgetContract;
use Capell\Admin\Contracts\Extensions\ExtensionTableDataSource;
use Capell\Admin\Data\ExtensionManagementEntryData;
use Capell\Admin\Data\Extensions\ExtensionOperationPackageData;
use Capell\Admin\Data\Extensions\ExtensionOperationsSummaryData;
use Capell\Admin\Filament\Concerns\GatedByRoleAndSettings;
use Capell\Admin\Filament\Pages\Extensions\Concerns\PreservesExtensionTablePosition;
use Capell\Admin\Filament\Pages\Extensions\Tables\ExtensionsTable;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Override;

final class InstalledExtensionsFilamentWidget extends TableWidget implements CapellFilamentWidgetContract, ExtensionTableDataSource
{
    use GatedByRoleAndSettings;
    use PreservesExtensionTablePosition;

    public ?string $activeProductGroup = null;

    /** @var list<string> */
    protected static array $rolesConfigKeys = [];

    protected static string $settingsKey = 'extensions.installed';

    protected int|string|array $columnSpan = ['default' => null, 'md' => 12, 'lg' => 12, 'xl' => 12];

    protected static ?int $sort = 30;

    private ?ExtensionOperationsSummaryData $operationsSummary = null;

    #[Override]
    public function table(Table $table): Table
    {
        return ExtensionsTable::configure($table)
            ->heading(null)
            ->description(null)
            ->queryStringIdentifier('installed-extensions');
    }

    public function getOperationsSummary(): ExtensionOperationsSummaryData
    {
        return $this->operationsSummary ??= BuildExtensionOperationsSummaryAction::run();
    }

    public function setProductGroup(?string $group): void
    {
        $this->activeProductGroup = $this->activeProductGroup === $group ? null : $group;
        $this->resetTable();
    }

    public function resetExtensionFilters(): void
    {
        $this->activeProductGroup = null;
        $this->resetTable();
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
}
