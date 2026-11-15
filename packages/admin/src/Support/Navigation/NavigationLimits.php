<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Navigation;

use Capell\Admin\Enums\NavigationGroupEnum;

/**
 * Sidebar size limits. Raising one is a reviewed product decision: change the
 * config default here and in config/capell-admin.php in the same pull request.
 */
final class NavigationLimits
{
    public static function maxGroups(): int
    {
        return (int) config('capell-admin.navigation.max_groups', 8);
    }

    public static function maxItemsPerGroup(): int
    {
        return max(2, (int) config('capell-admin.navigation.max_items_per_group', 10));
    }

    public static function minItemsPerGroup(): int
    {
        return (int) config('capell-admin.navigation.min_items_per_group', 2);
    }

    /**
     * Groups allowed to hold a single item. Media stays its own group even
     * when the library is its only screen.
     *
     * @return list<NavigationGroupEnum>
     */
    public static function singleItemGroups(): array
    {
        return [NavigationGroupEnum::Media];
    }
}
