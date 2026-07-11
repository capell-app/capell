<?php

declare(strict_types=1);

namespace Capell\Marketplace\Support;

final class MarketplaceInstallNotifications
{
    public static function operationId(string $composerName): string
    {
        return 'marketplace-install-' . hash('sha256', $composerName);
    }
}
