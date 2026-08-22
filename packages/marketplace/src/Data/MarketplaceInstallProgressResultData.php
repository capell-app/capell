<?php

declare(strict_types=1);

namespace Capell\Marketplace\Data;

use Spatie\LaravelData\Data;

final class MarketplaceInstallProgressResultData extends Data
{
    /** @param list<MarketplaceInstallProgressData> $items */
    public function __construct(public readonly array $items) {}

    /** @return list<int> */
    public function attemptIds(): array
    {
        return array_map(
            static fn (MarketplaceInstallProgressData $item): int => $item->id,
            $this->items,
        );
    }
}
