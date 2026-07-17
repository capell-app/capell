<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Concerns;

use Capell\Admin\Actions\Extensions\BuildExtensionOperationsSummaryAction;
use Capell\Admin\Actions\Extensions\EnrichExtensionTableRecordsAction;
use Capell\Admin\Actions\Extensions\FilterExtensionManagementEntriesAction;
use Capell\Admin\Actions\ListExtensionManagementEntriesAction;
use Capell\Admin\Data\ExtensionManagementEntryData;
use Capell\Admin\Data\Extensions\ExtensionOperationPackageData;
use Capell\Admin\Data\Extensions\ExtensionOperationsSummaryData;

trait InteractsWithExtensionTableData
{
    public ?string $activeProductGroup = null;

    private ?ExtensionOperationsSummaryData $operationsSummary = null;

    public function getOperationsSummary(): ExtensionOperationsSummaryData
    {
        return $this->operationsSummary ??= BuildExtensionOperationsSummaryAction::run();
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

        return $this->applyPinnedExtensionTablePosition(EnrichExtensionTableRecordsAction::run($records));
    }
}
