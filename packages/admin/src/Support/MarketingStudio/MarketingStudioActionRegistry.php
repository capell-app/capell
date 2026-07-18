<?php

declare(strict_types=1);

namespace Capell\Admin\Support\MarketingStudio;

use Capell\Admin\Data\MarketingStudioActionData;
use Capell\Core\Support\Registries\AbstractKeyedRegistry;
use Illuminate\Support\Collection;

/** @extends AbstractKeyedRegistry<MarketingStudioActionData> */
class MarketingStudioActionRegistry extends AbstractKeyedRegistry
{
    public function register(MarketingStudioActionData $action): void
    {
        if ($action->key !== '') {
            $this->setItem($action->key, $action);
        }
    }

    /** @return array<string, list<MarketingStudioActionData>> */
    public function groupedVisibleActions(): array
    {
        return collect($this->allItems())
            ->filter(fn (MarketingStudioActionData $action): bool => $action->isVisible())
            ->sortBy([
                fn (MarketingStudioActionData $action): int => $action->section->caseOrdinal(),
                fn (MarketingStudioActionData $action): int => $action->sort,
                fn (MarketingStudioActionData $action): string => $action->resolvedLabel(),
            ])
            ->groupBy(fn (MarketingStudioActionData $action): string => $action->section->value)
            ->map(fn (Collection $actions): array => array_values($actions->values()->all()))
            ->all();
    }
}
