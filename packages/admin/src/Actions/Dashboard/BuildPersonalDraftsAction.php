<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Dashboard;

use Capell\Admin\Data\Dashboard\DashboardWorkData;
use Capell\Admin\Data\Dashboard\DashboardWorkItemData;
use Capell\Core\Actions\GetEditPageResourceUrlAction;
use Capell\Core\Models\Page;
use Capell\Core\Support\Permissions\SiteAccess;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

final class BuildPersonalDraftsAction
{
    public function handle(Authenticatable $actor, ?int $siteId, int $limit, ?string $language = null): DashboardWorkData
    {
        if (! Gate::forUser($actor)->allows('viewAny', Page::class)) {
            return new DashboardWorkData(true, 0, []);
        }

        $pages = SiteAccess::forActor($actor)->query(Page::class)
            ->with('site')
            ->draft()
            ->where('created_by', $actor->getAuthIdentifier())
            ->when($siteId !== null, fn (Builder $query): Builder => $query->where('site_id', $siteId))
            ->when($language !== null, fn (Builder $query): Builder => $query->whereHas('translations.language', fn (Builder $query): Builder => $query->where('code', $language)))
            ->latest('updated_at')
            ->lazy();

        $items = [];
        $total = 0;
        foreach ($pages as $page) {
            if (! Gate::forUser($actor)->allows('view', $page)) {
                continue;
            }

            $total++;
            if (count($items) >= max(0, $limit)) {
                continue;
            }

            $items[] = new DashboardWorkItemData(
                pageId: (int) $page->getKey(),
                siteId: (int) $page->site_id,
                siteName: (string) $page->site->name,
                title: $page->name,
                reason: 'draft',
                deadline: null,
                updatedAt: $page->updated_at?->toIso8601String(),
                editUrl: Gate::forUser($actor)->allows('update', $page) ? GetEditPageResourceUrlAction::run($page) : null,
            );
        }

        return new DashboardWorkData(true, $total, $items);
    }
}
