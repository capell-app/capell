<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Dashboard;

use Capell\Admin\Contracts\Dashboard\DashboardAttentionContributor;
use Capell\Admin\Data\Dashboard\DashboardAttentionItemData;
use Capell\Admin\Support\Dashboard\DashboardDeadline;
use Capell\Admin\Support\Dashboard\DashboardSiteEvaluator;
use Capell\Core\Actions\GetEditPageResourceUrlAction;
use Capell\Core\Enums\PublishVisibilityStateEnum;
use Capell\Core\Models\Page;
use Capell\Core\Support\Permissions\SiteAccess;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class BuildWorkFirstAttentionAction
{
    use AsFake;
    use AsObject;

    /** @return list<DashboardAttentionItemData> */
    public function handle(Authenticatable $actor, ?int $siteId, ?string $language): array
    {
        $items = [];
        $evaluator = resolve(DashboardSiteEvaluator::class);
        foreach ($evaluator->siteIds($actor, $siteId) as $candidate) {
            $items = [...$items, ...$evaluator->run($actor, $candidate, fn (): array => $this->handleSite($actor, $candidate, $language))];
        }

        usort($items, static fn ($left, $right): int => [$left->urgent ? 0 : 1, $left->deadline === null ? 1 : 0, DashboardDeadline::timestamp($left->deadline), $left->key] <=> [$right->urgent ? 0 : 1, $right->deadline === null ? 1 : 0, DashboardDeadline::timestamp($right->deadline), $right->key]);

        return $items;
    }

    /** @return list<DashboardAttentionItemData> */
    private function handleSite(Authenticatable $actor, ?int $siteId, ?string $language): array
    {
        $items = [];
        if (Gate::forUser($actor)->allows('viewAny', Page::class)) {
            $pages = SiteAccess::forActor($actor)->query(Page::class)
                ->when($siteId !== null, fn (Builder $query): Builder => $query->where('site_id', $siteId))
                ->with(['site', 'blueprint', 'layout'])
                ->withCount(['translations', 'pageUrls as active_url_count' => fn (Builder $query): Builder => $query->enabled()])
                ->orderBy('id')->lazy();

            foreach ($pages as $page) {
                if (! Gate::forUser($actor)->allows('view', $page)) {
                    continue;
                }

                $reasons = resolve(BuildDashboardPageBlockersAction::class)->handle($page);
                if ($reasons === []) {
                    continue;
                }

                $urgent = $page->publishVisibilityState() === PublishVisibilityStateEnum::published;
                if (! $urgent && $language !== null && ! SiteAccess::forActor($actor)->scope($page->translations()->getQuery())->whereHas('language', fn (Builder $query): Builder => $query->where('code', $language))->exists()) {
                    continue;
                }

                $items[] = new DashboardAttentionItemData(
                    key: 'page-setup-' . $page->getKey(),
                    fingerprint: hash('sha256', json_encode([$reasons, $urgent], JSON_THROW_ON_ERROR)),
                    urgent: $urgent,
                    title: $page->name,
                    siteId: (int) $page->site_id,
                    siteName: $page->site->name,
                    description: (string) ($urgent ? __('capell-admin::work-first.public_page_blocked') : __('capell-admin::work-first.content_setup')),
                    reasons: $reasons,
                    url: $page->blueprint !== null && Gate::forUser($actor)->allows('update', $page)
                        ? GetEditPageResourceUrlAction::run($page) : null,
                    deadline: $page->publishVisibilityState() === PublishVisibilityStateEnum::scheduled ? $page->visible_from?->toIso8601String() : null,
                    pageId: (int) $page->getKey(),
                );
            }
        }

        foreach (app()->tagged(DashboardAttentionContributor::TAG) as $contributor) {
            if ($contributor instanceof DashboardAttentionContributor) {
                foreach ($contributor->build($actor, $siteId, $language) as $item) {
                    if ($item instanceof DashboardAttentionItemData && resolve(CanViewDashboardItemAction::class)->handle($actor, $item->siteId, $siteId, $item->pageId, $item->urgent ? null : $language)) {
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
        usort($items, static fn (DashboardAttentionItemData $left, DashboardAttentionItemData $right): int => [
            $left->urgent ? 0 : 1, $left->deadline === null ? 1 : 0, DashboardDeadline::timestamp($left->deadline), $left->key,
        ] <=> [
            $right->urgent ? 0 : 1, $right->deadline === null ? 1 : 0, DashboardDeadline::timestamp($right->deadline), $right->key,
        ]);

        return $items;
    }
}
