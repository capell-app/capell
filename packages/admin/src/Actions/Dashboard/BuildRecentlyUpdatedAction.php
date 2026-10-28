<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Dashboard;

use Capell\Admin\Data\Dashboard\DashboardRecentlyUpdatedData;
use Capell\Admin\Data\Dashboard\DashboardRecentlyUpdatedItemData;
use Capell\Core\Actions\GetEditPageResourceUrlAction;
use Capell\Core\Models\Page;
use Capell\Core\Support\Permissions\SiteAccess;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

final class BuildRecentlyUpdatedAction
{
    public function handle(Authenticatable $actor, ?int $siteId, ?string $language, int $limit): DashboardRecentlyUpdatedData
    {
        if ($limit < 1 || ! Gate::forUser($actor)->allows('viewAny', Page::class)) {
            return new DashboardRecentlyUpdatedData(0, []);
        }

        $pages = SiteAccess::forActor($actor)->query(Page::class)
            ->with(['site', 'editor'])
            ->when($siteId !== null, fn (Builder $query): Builder => $query->where('site_id', $siteId))
            ->when($language !== null, fn (Builder $query): Builder => $query->whereHas('translations.language', fn (Builder $query): Builder => $query->where('code', $language)))
            ->latest('updated_at')->latest('id')->lazy();
        $items = [];
        $total = 0;
        foreach ($pages as $page) {
            if (! Gate::forUser($actor)->allows('view', $page)) {
                continue;
            }

            if ($page->site === null) {
                continue;
            }

            $total++;
            if (count($items) >= $limit) {
                continue;
            }

            $items[] = new DashboardRecentlyUpdatedItemData(
                title: $page->name,
                siteName: $page->site->name,
                editor: (string) (data_get($page->editor, 'name', __('capell-admin::dashboard.work_first_unknown_editor'))),
                updatedAt: $page->updated_at?->toIso8601String(),
                editUrl: Gate::forUser($actor)->allows('update', $page) && $page->blueprint_id !== null ? GetEditPageResourceUrlAction::run($page) : null,
            );
        }

        return new DashboardRecentlyUpdatedData($total, $items);
    }
}
