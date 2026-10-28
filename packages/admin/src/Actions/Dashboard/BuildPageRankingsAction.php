<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Dashboard;

use Capell\Admin\Contracts\Dashboard\DashboardPageRankingProvider;
use Capell\Admin\Data\Dashboard\DashboardPageRankingItemData;
use Capell\Admin\Data\Dashboard\DashboardPageRankingsData;
use Capell\Admin\Data\Dashboard\DashboardPageRankingSourceData;
use Capell\Admin\Support\Dashboard\DashboardSiteEvaluator;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Support\Permissions\SiteAccess;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Throwable;

final class BuildPageRankingsAction
{
    public function handle(Authenticatable $actor, ?int $siteId, ?string $language, int $days, bool $rising, int $limit = 5): DashboardPageRankingsData
    {
        if (! resolve(CanViewWorkFirstAnalyticsAction::class)->handle($actor, $siteId)) {
            return new DashboardPageRankingsData('unauthorised', '', '', '', [], null);
        }

        if ($days < 1 || $days > 365) {
            return new DashboardPageRankingsData('insufficient', '', '', '', [], null);
        }

        $mapping = config('capell-admin.dashboard.work_first.analytics.rankings', []);
        if (! is_array($mapping) || ($mapping['enabled'] ?? false) !== true
            || ! is_string($mapping['measure'] ?? null) || $mapping['measure'] === ''
            || ! is_string($mapping['source'] ?? null) || $mapping['source'] === '') {
            return new DashboardPageRankingsData('unavailable', '', '', '', [], null);
        }

        $evaluator = resolve(DashboardSiteEvaluator::class);
        $sites = SiteAccess::forActor($actor, acrossAssignedSites: true)->query(Site::class)->enabled()
            ->with(['language', 'siteDomains.language'])
            ->whereIn('id', $evaluator->siteIds($actor, $siteId))
            ->get()
            ->filter(fn (Site $site): bool => $evaluator->allows($actor, (int) $site->getKey(), fn (): bool => resolve(CanViewWorkFirstAnalyticsAction::class)->handle($actor, (int) $site->getKey()) && Gate::forUser($actor)->allows('view', $site) && Gate::forUser($actor)->allows('viewAny', Page::class)))
            ->filter(fn (Site $site): bool => $language === null || $site->getAllLanguages()->contains(fn (mixed $item): bool => $item instanceof Language && $item->code === $language));
        if ($sites->isEmpty()) {
            return new DashboardPageRankingsData('insufficient', '', '', '', [], null);
        }

        $siteIdentities = $sites->map(function (Site $site): array {
            $languages = $site->getAllLanguages()->filter(fn (mixed $item): bool => $item instanceof Language)->map(fn (Language $item): string => $item->code)->sort()->values()->all();

            return [(int) $site->getKey(), $site->uuid, $languages];
        })->sortBy(fn (array $site): int => $site[0])->values()->all();
        $cacheKey = 'capell:dashboard:rankings:' . hash('sha256', json_encode([$actor::class, $actor->getAuthIdentifier(), $siteId, $language, $days, $mapping, $siteIdentities], JSON_THROW_ON_ERROR));
        try {
            $source = $this->sourceForSites($actor, array_values($sites->map(static fn (Site $site): int => (int) $site->getKey())->all()), $language, $days, $evaluator);
            if (! $source instanceof DashboardPageRankingSourceData || $source->status === 'failed') {
                $source = $this->staleSource($cacheKey, $days);
            } elseif ($source->status === 'stale' && ! $source->currentComplete) {
                $source = $this->staleSource($cacheKey, $days) ?? $source;
            }
        } catch (Throwable $throwable) {
            report($throwable);
            $source = $this->staleSource($cacheKey, $days);
        }

        if (! $source instanceof DashboardPageRankingSourceData) {
            return new DashboardPageRankingsData('failed', '', '', '', [], null);
        }

        if (! $source->available) {
            return new DashboardPageRankingsData('unavailable', '', '', '', [], null);
        }

        if (! in_array($source->status, ['available', 'stale'], true) || $source->lastUpdated === null
            || $source->measure !== $mapping['measure'] || $source->source !== $mapping['source']
            || ! in_array($source->timezone, DateTimeZone::listIdentifiers(), true)
            || ! resolve(ValidateDashboardReportingPeriodAction::class)->handle($source->periodStart, $source->periodEnd, $source->timezone, $days, $source->status)
            || $source->sitesRequested !== $sites->count() || $source->sitesCovered !== $sites->count()
            || ! $source->currentComplete || ($rising && ! $source->previousComplete)) {
            return new DashboardPageRankingsData('insufficient', $source->measure, $source->source, $source->timezone, [], $source->reportUrl, $sites->count(), max(0, min($sites->count(), $source->sitesCovered)));
        }

        $ids = array_map(static fn (DashboardPageRankingItemData $item): int => $item->pageId, $source->items);
        if (count($ids) !== count(array_unique($ids))) {
            return new DashboardPageRankingsData('insufficient', $source->measure, $source->source, $source->timezone, [], null);
        }

        $pages = SiteAccess::forActor($actor, acrossAssignedSites: true)->query(Page::class)
            ->whereIn('site_id', $sites->modelKeys())
            ->when($language !== null, fn (Builder $query): Builder => $query->whereHas('translations.language', fn (Builder $query): Builder => $query->where('code', $language)))
            ->whereIn('id', array_map(static fn (DashboardPageRankingItemData $item): int => $item->pageId, $source->items))
            ->get()->keyBy('id');
        $authorised = collect($source->items)
            ->filter(function (DashboardPageRankingItemData $item) use ($pages, $actor, $evaluator): bool {
                $page = $pages->get($item->pageId);

                return $page instanceof Page && (int) $page->site_id === $item->siteId
                    && $evaluator->allows($actor, $item->siteId, fn (): bool => Gate::forUser($actor)->allows('view', $page)) && $item->current >= 0
                    && ($item->previous === null || $item->previous >= 0);
            })->values();
        if ($source->status === 'available') {
            $safeItems = $authorised->map(fn (DashboardPageRankingItemData $item): DashboardPageRankingItemData => new DashboardPageRankingItemData(
                $item->pageId,
                $item->siteId,
                $item->siteName,
                $item->title,
                $item->current,
                $item->previous,
                $item->isNew,
            ))->all();
            $snapshot = new DashboardPageRankingSourceData(true, $source->currentComplete, $source->previousComplete, $source->measure, $source->source, $source->timezone, array_values($safeItems), null, $source->sitesRequested, $source->sitesCovered, 'available', $source->lastUpdated, $source->periodStart, $source->periodEnd);
            try {
                Cache::forever($cacheKey, $snapshot);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        $eligible = $authorised->filter(function (DashboardPageRankingItemData $item) use ($rising): bool {
            if ($item->current < 1) {
                return false;
            }

            if (! $rising) {
                return true;
            }

            if ($item->current < 10 || $item->previous === null) {
                return false;
            }

            if ($item->previous === 0) {
                return $item->isNew;
            }

            $growth = $item->current - $item->previous;

            return $growth >= 5 && $growth / $item->previous >= 0.2;
        });
        $total = $eligible->count();
        $items = $eligible->sortByDesc(fn (DashboardPageRankingItemData $item): int => $rising ? $item->current - ($item->previous ?? 0) : $item->current)
            ->take(max(0, min(8, $limit)))
            ->map(function (DashboardPageRankingItemData $item) use ($pages, $actor, $evaluator): ?DashboardPageRankingItemData {
                $page = $pages->get($item->pageId);
                if (! $page instanceof Page) {
                    return null;
                }

                return new DashboardPageRankingItemData(
                    $item->pageId,
                    $item->siteId,
                    $item->siteName,
                    $page->name,
                    $item->current,
                    $item->previous,
                    $item->isNew,
                    $item->publicUrl,
                    $evaluator->allows($actor, $item->siteId, fn (): bool => Gate::forUser($actor)->allows('update', $page)) ? $item->editUrl : null,
                    $item->performanceUrl,
                );
            })->filter()->values()->all();

        return new DashboardPageRankingsData($source->status, $source->measure, $source->source, $source->timezone, array_values($items), $source->reportUrl, $sites->count(), $sites->count(), $total, $source->lastUpdated, $source->periodStart, $source->periodEnd);
    }

    /** @param list<int> $siteIds */
    private function sourceForSites(Authenticatable $actor, array $siteIds, ?string $language, int $days, DashboardSiteEvaluator $evaluator): ?DashboardPageRankingSourceData
    {
        $first = null;
        $items = [];
        $covered = 0;
        $currentComplete = true;
        $previousComplete = true;
        $status = 'available';
        $updated = null;
        foreach ($siteIds as $siteId) {
            $part = $evaluator->run($actor, $siteId, fn (): DashboardPageRankingSourceData => resolve(DashboardPageRankingProvider::class)->build($actor, $siteId, $language, $days));
            if ($part->status === 'failed') {
                return null;
            }

            if (! $part->available) {
                return $part;
            }

            if ($first !== null && ($part->measure !== $first->measure || $part->source !== $first->source || $part->timezone !== $first->timezone
                || $part->periodStart !== $first->periodStart || $part->periodEnd !== $first->periodEnd)) {
                $currentComplete = false;
                $previousComplete = false;
            }

            $first ??= $part;
            $currentComplete = $currentComplete && $part->currentComplete && $part->sitesRequested === 1 && $part->sitesCovered === 1;
            $previousComplete = $previousComplete && $part->previousComplete;
            $covered += $part->sitesRequested === 1 && $part->sitesCovered === 1 ? 1 : 0;
            if ($part->status === 'stale') {
                $status = 'stale';
            } elseif ($part->status !== 'available') {
                $currentComplete = false;
            }

            if ($part->lastUpdated === null) {
                $currentComplete = false;
            } elseif ($updated === null || CarbonImmutable::parse($part->lastUpdated)->lessThan(CarbonImmutable::parse($updated))) {
                $updated = $part->lastUpdated;
            }

            array_push($items, ...$part->items);
        }

        if (! $first instanceof DashboardPageRankingSourceData) {
            return null;
        }

        return new DashboardPageRankingSourceData(true, $currentComplete, $previousComplete, $first->measure, $first->source, $first->timezone, $items, count($siteIds) === 1 ? $first->reportUrl : null, count($siteIds), $covered, $status, $updated, $first->periodStart, $first->periodEnd);
    }

    private function staleSource(string $cacheKey, int $days): ?DashboardPageRankingSourceData
    {
        try {
            $last = Cache::get($cacheKey);
        } catch (Throwable $throwable) {
            report($throwable);

            return null;
        }

        if (! $last instanceof DashboardPageRankingSourceData || $last->lastUpdated === null
            || ! resolve(ValidateDashboardReportingPeriodAction::class)->handle($last->periodStart, $last->periodEnd, $last->timezone, $days, 'stale')) {
            return null;
        }

        return new DashboardPageRankingSourceData(true, $last->currentComplete, $last->previousComplete, $last->measure, $last->source, $last->timezone, $last->items, null, $last->sitesRequested, $last->sitesCovered, 'stale', $last->lastUpdated, $last->periodStart, $last->periodEnd);
    }
}
