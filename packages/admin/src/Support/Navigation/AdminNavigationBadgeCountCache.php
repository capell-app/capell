<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Navigation;

use Capell\Admin\Actions\ScopePageRestoreVisibilityAction;
use Capell\Core\Models\Page;
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
        if (isset($this->counts[$resource])) {
            return $this->counts[$resource];
        }

        $query = $resource::getEloquentQuery();

        if ($query->getModel() instanceof Page) {
            return $this->counts[$resource] = ScopePageRestoreVisibilityAction::make()->count($query);
        }

        return $this->counts[$resource] = $query->count();
    }
}
