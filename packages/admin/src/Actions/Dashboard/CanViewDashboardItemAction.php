<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Dashboard;

use Capell\Admin\Support\Dashboard\DashboardSiteEvaluator;
use Capell\Core\Models\Page;
use Capell\Core\Support\Permissions\SiteAccess;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

final class CanViewDashboardItemAction
{
    public function handle(Authenticatable $actor, int $siteId, ?int $selectedSiteId, ?int $pageId = null, ?string $language = null): bool
    {
        if ($selectedSiteId !== null && $selectedSiteId !== $siteId) {
            return false;
        }

        return resolve(DashboardSiteEvaluator::class)->allows($actor, $siteId, fn (): bool => $this->withinSite($actor, $siteId, $pageId, $language));
    }

    private function withinSite(Authenticatable $actor, int $siteId, ?int $pageId, ?string $language): bool
    {
        $access = SiteAccess::forActor($actor);
        if (! $access->canSiteId($siteId)) {
            return false;
        }

        if ($pageId === null) {
            return Gate::forUser($actor)->allows('viewAny', Page::class);
        }

        // Optional workflows can hold non-live page rows. Keep their context
        // independent while explicitly retaining actor/site/deletion checks.
        $page = $access->scope(Page::query()->withoutGlobalScopes())
            ->whereNull('deleted_at')->whereKey($pageId)->where('site_id', $siteId)
            ->when($language !== null, fn (Builder $query): Builder => $query->whereHas('translations.language', fn (Builder $languages): Builder => $languages->where('code', $language)))
            ->first();

        return $page instanceof Page && Gate::forUser($actor)->allows('view', $page);
    }
}
