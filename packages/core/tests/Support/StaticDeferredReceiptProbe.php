<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support;

use Capell\Core\Enums\ExtensionContributionType;
use Capell\Core\Support\Extensions\ExtensionContributionReceiptRegistry;

final class StaticDeferredReceiptProbe
{
    public static function run(): void
    {
        resolve(ExtensionContributionReceiptRegistry::class)->recordFromContext(
            ExtensionContributionType::RenderHook,
            'vendor.deferred.hook',
            self::class,
            self::class,
        );
    }
}
