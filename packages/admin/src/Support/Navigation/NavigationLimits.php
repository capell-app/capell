<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Navigation;

/**
 * Sidebar size limits. Raising one is a reviewed product decision: change the
 * config default here and in config/capell-admin.php in the same pull request.
 */
final class NavigationLimits
{
    public static function maxGroups(): int
    {
        return (int) config('capell-admin.navigation.max_groups', 9);
    }

    public static function maxItemsPerGroup(): int
    {
        return max(2, (int) config('capell-admin.navigation.max_items_per_group', 10));
    }

    public static function minItemsPerGroup(): int
    {
        return (int) config('capell-admin.navigation.min_items_per_group', 2);
    }
}
