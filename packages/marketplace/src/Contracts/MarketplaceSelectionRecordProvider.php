<?php

declare(strict_types=1);

namespace Capell\Marketplace\Contracts;

use Capell\Marketplace\Data\MarketplaceSelectionRecordData;

interface MarketplaceSelectionRecordProvider
{
    /**
     * @param  list<string>  $composerNames
     * @return array<string, MarketplaceSelectionRecordData>
     */
    public function selectionRecordsByComposerNames(
        array $composerNames,
        ?string $lockedKind = null,
        bool $includeLocalExtensionState = true,
    ): array;
}
