<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Navigation;

use Filament\Resources\Resource;

final class AdminNavigationBadgeCountCache
{
    /** @var array<class-string<resource>, int> */
    private array $counts = [];

    /**
     * @param  class-string<resource>  $resource
     */
    public function count(string $resource): int
    {
        return $this->counts[$resource] ??= $resource::getEloquentQuery()->count();
    }
}
