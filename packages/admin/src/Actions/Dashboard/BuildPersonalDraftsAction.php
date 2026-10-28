<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Dashboard;

use Capell\Admin\Data\Dashboard\DashboardWorkData;
use Capell\Admin\Data\Dashboard\DashboardWorkItemData;
use Capell\Admin\Filament\Resources\Pages\PageResource;
use Capell\Admin\Support\Dashboard\DashboardSiteEvaluator;
use Capell\Core\Actions\GetEditPageResourceUrlAction;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Support\Permissions\SiteAccess;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

final class BuildPersonalDraftsAction
{
    /** @param list<int> $retainedPageIds */
    public function handle(Authenticatable $actor, ?int $siteId, int $limit, ?string $language = null, array $retainedPageIds = []): DashboardWorkData
    {
        $items = [];
        $total = 0;
        $evaluator = resolve(DashboardSiteEvaluator::class);
        $siteIds = $evaluator->siteIds($actor, $siteId);
        foreach ($siteIds as $candidate) {
            $result = $evaluator->run($actor, $candidate, fn (): DashboardWorkData => $this->handleSite($actor, $candidate, $limit, $language, $retainedPageIds));
            $total += $result->total;
            $items = [...$items, ...$result->items];
        }

        usort($items, static fn ($left, $right): int => ($right->updatedAt ?? '') <=> ($left->updatedAt ?? ''));

        $listUrl = null;
        if (($siteId !== null && in_array($siteId, $siteIds, true)) || ($siteId === null && SiteAccess::forActor($actor, acrossAssignedSites: true)->isGlobal())) {
            $filters = ['owned_drafts' => ['isActive' => true], 'site_id' => ['value' => '']];
            if ($siteId !== null && $siteId > 0) {
                $filters['site_id'] = ['value' => $siteId];
            }

            if ($language !== null) {
                $languageId = Language::query()->where('code', $language)->value('id');
                if ($languageId !== null) {
                    $filters['filter'] = ['language_id' => $languageId];
                } else {
                    return new DashboardWorkData(true, $total, array_values(array_filter($items, static fn (DashboardWorkItemData $item, int $index): bool => $index < max(0, $limit) || in_array($item->pageId, $retainedPageIds, true), ARRAY_FILTER_USE_BOTH)));
                }
            }

            $listUrl = PageResource::getUrl(parameters: ['site' => $siteId, 'tableFilters' => $filters]);
        }

        return new DashboardWorkData(true, $total, array_values(array_filter($items, static fn (DashboardWorkItemData $item, int $index): bool => $index < max(0, $limit) || in_array($item->pageId, $retainedPageIds, true), ARRAY_FILTER_USE_BOTH)), $listUrl);
    }

    /** @param list<int> $retainedPageIds */
    private function handleSite(Authenticatable $actor, ?int $siteId, int $limit, ?string $language = null, array $retainedPageIds = []): DashboardWorkData
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
            if (count($items) >= max(0, $limit) && ! in_array((int) $page->getKey(), $retainedPageIds, true)) {
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
