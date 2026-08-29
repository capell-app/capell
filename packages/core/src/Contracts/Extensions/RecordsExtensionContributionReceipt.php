<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Extensions;

use Capell\Core\Data\Extensions\ExtensionContributionReceiptData;

interface RecordsExtensionContributionReceipt
{
    public function record(ExtensionContributionReceiptData $receipt): void;
}
