<?php

declare(strict_types=1);

namespace Capell\Marketplace\Enums;

enum MarketplacePermission: string
{
    case ViewExtensionsPage = 'View:ExtensionsPage';
    case ViewMarketplacePage = 'View:MarketplacePage';

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_map(
            fn (self $permission): string => $permission->value,
            self::cases(),
        );
    }
}
