<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Dashboard;

use Capell\Admin\Contracts\Dashboard\DashboardPublishingContributor;
use Capell\Admin\Data\Dashboard\DashboardPublicationItemData;
use Capell\Admin\Support\Dashboard\DashboardSiteEvaluator;
use Capell\Core\Actions\GetEditPageResourceUrlAction;
use Capell\Core\Models\Page;
use Capell\Core\Support\Permissions\SiteAccess;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class BuildWorkFirstPublishingAction
{
    use AsFake;
    use AsObject;

    /** @return list<DashboardPublicationItemData> */
    public function handle(Authenticatable $actor, ?int $siteId, ?string $language): array
    {
        $items = [];
        $evaluator = resolve(DashboardSiteEvaluator::class);
        foreach ($evaluator->siteIds($actor, $siteId) as $candidate) {
            $items = [...$items, ...$evaluator->run($actor, $candidate, fn (): array => $this->handleSite($actor, $candidate, $language))];
        }

        usort($items, static fn ($left, $right): int => [CarbonImmutable::parse($left->scheduledAt)->getTimestamp(), $left->key] <=> [CarbonImmutable::parse($right->scheduledAt)->getTimestamp(), $right->key]);

        return $items;
    }

    /** @return list<DashboardPublicationItemData> */
    private function handleSite(Authenticatable $actor, ?int $siteId, ?string $language): array
    {
        if (! Gate::forUser($actor)->allows('viewAny', Page::class)) {
            return [];
        }

        $pages = SiteAccess::forActor($actor)->query(Page::class)->scheduled()
            ->where('visible_from', '<=', CarbonImmutable::now()->addDays(7))
            ->when($siteId !== null, fn (Builder $query): Builder => $query->where('site_id', $siteId))
            ->when($language !== null, fn (Builder $query): Builder => $query->whereHas('translations.language', fn (Builder $query): Builder => $query->where('code', $language)))
            ->with(['site', 'blueprint', 'layout'])
            ->withCount(['translations', 'pageUrls as active_url_count' => fn (Builder $query): Builder => $query->enabled()])
            ->orderBy('visible_from')->orderBy('id')->get();
        $items = [];
        foreach ($pages as $page) {
            if (! Gate::forUser($actor)->allows('view', $page)) {
                continue;
            }

            if ($page->site === null) {
                continue;
            }

            if ($page->visible_from === null) {
                continue;
            }

            $reasons = resolve(BuildDashboardPageBlockersAction::class)->handle($page);
            $timezone = resolve(ResolveDashboardSiteTimezoneAction::class)->handle($page->site);
            $items[] = new DashboardPublicationItemData(
                key: 'page-' . $page->getKey(),
                pageId: (int) $page->getKey(),
                siteId: (int) $page->site_id,
                siteName: $page->site->name,
                title: $page->name,
                scheduledAt: CarbonImmutable::instance($page->visible_from)->setTimezone($timezone)->toIso8601String(),
                timezone: $timezone,
                readiness: $reasons === [] ? 'ready' : 'blocked',
                reasons: $reasons,
                url: Gate::forUser($actor)->allows('update', $page) && $page->blueprint !== null
                    ? GetEditPageResourceUrlAction::run($page) : null,
            );
        }

        foreach (app()->tagged(DashboardPublishingContributor::TAG) as $contributor) {
            if ($contributor instanceof DashboardPublishingContributor) {
                foreach ($contributor->build($actor, $siteId, $language) as $item) {
                    if ($item instanceof DashboardPublicationItemData && resolve(CanViewDashboardItemAction::class)->handle($actor, $item->siteId, $siteId, $item->pageId, $language)) {
                        $items[] = $item;
                    }
                }
            }
        }

        $unique = [];
        foreach ($items as $item) {
            $unique[$item->key] = $item;
        }

        $items = array_values($unique);
        usort($items, static fn (DashboardPublicationItemData $left, DashboardPublicationItemData $right): int => [
            CarbonImmutable::parse($left->scheduledAt)->getTimestamp(), $left->key,
        ] <=> [CarbonImmutable::parse($right->scheduledAt)->getTimestamp(), $right->key]);

        return $items;
    }
}
