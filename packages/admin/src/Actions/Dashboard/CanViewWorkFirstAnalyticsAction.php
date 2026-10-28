<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Dashboard;

use Capell\Admin\Support\Dashboard\DashboardSiteEvaluator;
use Capell\Core\Support\Permissions\SiteAccess;
use Illuminate\Contracts\Auth\Authenticatable;

final class CanViewWorkFirstAnalyticsAction
{
    public function handle(Authenticatable $actor, ?int $siteId = null): bool
    {
        $evaluator = resolve(DashboardSiteEvaluator::class);
        foreach ($evaluator->siteIds($actor, $siteId) as $candidate) {
            if ($evaluator->allows($actor, $candidate, fn (): bool => $this->withinSite($actor))) {
                return true;
            }
        }

        return false;
    }

    private function withinSite(Authenticatable $actor): bool
    {
        if (SiteAccess::forActor($actor)->isGlobal()) {
            return true;
        }

        $role = config('capell.roles.admin');

        return is_string($role) && $role !== '' && method_exists($actor, 'hasRole') && $actor->hasRole($role);
    }
}
