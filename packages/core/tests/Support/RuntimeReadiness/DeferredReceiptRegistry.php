<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support\RuntimeReadiness;

use Capell\Core\Enums\ExtensionContributionReceiptType;
use Capell\Core\Support\Extensions\ExtensionContributionReceiptRegistry;

final class DeferredReceiptRegistry
{
    public function record(string $key): void
    {
        resolve(ExtensionContributionReceiptRegistry::class)->recordFromContext(
            ExtensionContributionReceiptType::ContentGraph,
            $key,
            self::class,
        );
    }
}
