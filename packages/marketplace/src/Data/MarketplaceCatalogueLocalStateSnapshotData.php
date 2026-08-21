<?php

declare(strict_types=1);

namespace Capell\Marketplace\Data;

use Spatie\LaravelData\Data;

final class MarketplaceCatalogueLocalStateSnapshotData extends Data
{
    /**
     * @param  list<string>  $downloadedComposerNames
     * @param  array<string, string|null>  $installedVersions
     * @param  array<string, MarketplaceCatalogueOperationData>  $activeOperations
     */
    public function __construct(
        public readonly array $downloadedComposerNames,
        public readonly array $installedVersions,
        public readonly array $activeOperations,
    ) {}

    public static function withoutLocalState(): self
    {
        return new self([], [], []);
    }
}
