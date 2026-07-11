<?php

declare(strict_types=1);

namespace Capell\Marketplace\Filament\Support;

use Filament\Tables\Table;

final class MarketplaceBrowser
{
    public function __construct(private readonly MarketplaceCatalogueTable $catalogueTable) {}

    /**
     * @param  array<string, mixed>|null  $filters
     * @return array<int, array<string, mixed>>
     */
    public function records(
        ?string $search = null,
        ?array $filters = null,
        ?string $lockedKind = null,
        bool $includeLocalExtensionState = true,
    ): array {
        return $this->catalogueTable->records(
            search: $search,
            filters: $filters ?? [],
            lockedKind: $lockedKind,
            includeLocalExtensionState: $includeLocalExtensionState,
        );
    }

    /**
     * @param  array<int, string>  $composerNames
     * @return array<string, array<string, mixed>>
     */
    public function recordsByComposerNames(
        array $composerNames,
        ?string $lockedKind = null,
        bool $includeLocalExtensionState = true,
    ): array {
        return $this->catalogueTable->recordsByComposerNames(
            composerNames: $composerNames,
            lockedKind: $lockedKind,
            includeLocalExtensionState: $includeLocalExtensionState,
        );
    }

    public function table(Table $table, ?string $lockedKind = null, bool $includeLocalExtensionState = true): Table
    {
        return $this->catalogueTable->configure(
            table: $table,
            lockedKind: $lockedKind,
            includeLocalExtensionState: $includeLocalExtensionState,
            forceAvailableOnly: true,
        );
    }

    public function queueDefaultWarm(?string $lockedKind = null, bool $includeLocalExtensionState = true): bool
    {
        return $this->catalogueTable->queueDefaultWarm($lockedKind, $includeLocalExtensionState);
    }
}
