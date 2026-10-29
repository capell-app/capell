<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Dashboard;

use Capell\Core\Models\Site;
use Capell\Core\Support\Permissions\PermissionTeamContext;
use Capell\Core\Support\Permissions\SiteAccess;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

final class DashboardSiteEvaluator
{
    /** @return list<int> */
    public function siteIds(Authenticatable $actor, ?int $selectedSiteId = null): array
    {
        $query = SiteAccess::forActor($actor, acrossAssignedSites: true)->query(Site::class);
        if ($selectedSiteId !== null) {
            $query->whereKey($selectedSiteId);
        }

        return array_values($query->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all());
    }

    /** @template TResult
     * @param  Closure(): TResult  $operation
     * @return TResult
     */
    public function run(Authenticatable $actor, int $siteId, Closure $operation): mixed
    {
        if (SiteAccess::forActor($actor, acrossAssignedSites: true)->isGlobal()) {
            return $operation();
        }

        return PermissionTeamContext::run($siteId, $operation, $actor instanceof Model ? $actor : null);
    }

    /** @param Closure(): bool $operation */
    public function allows(Authenticatable $actor, int $siteId, Closure $operation): bool
    {
        return $this->siteIds($actor, $siteId) !== [] && $this->run($actor, $siteId, $operation);
    }
}
