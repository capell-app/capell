<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Extensions;

use Capell\Core\Enums\ExtensionContributionReceiptType;
use Capell\Core\Enums\ExtensionContributionType;

interface RecordsExtensionContributionReceipt
{
    public function recordContribution(
        ExtensionContributionType|ExtensionContributionReceiptType $type,
        string $key,
        string $implementation,
        string $sourceClass,
        ?string $providerBucket = null,
    ): void;

    public function recordContributionFromSource(
        ExtensionContributionType|ExtensionContributionReceiptType $type,
        string $key,
        string $implementation,
        string $sourceClass,
        ?string $providerBucket = null,
    ): void;
}
