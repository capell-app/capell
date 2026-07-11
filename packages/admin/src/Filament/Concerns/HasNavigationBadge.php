<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Concerns;

use Capell\Admin\Support\Navigation\AdminNavigationBadgeCountCache;
use Filament\Resources\Resource;

/**
 * @mixin Resource
 */
trait HasNavigationBadge
{
    public static function getNavigationBadge(): ?string
    {
        $name = str(static::class)->afterLast('\\')->beforeLast('Resource')->snake()->toString();

        if (config(sprintf('capell-admin.resources.%s.navigation_badge', $name)) === false) {
            return null;
        }

        if (config('capell-admin.navigation_badge_counts') === false) {
            return null;
        }

        $count = resolve(AdminNavigationBadgeCountCache::class)->count(static::class);

        if ($count === 0) {
            return null;
        }

        return number_format($count);
    }
}
