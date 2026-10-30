<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Dashboard;

use Capell\Admin\Data\Dashboard\SiteStatsData;
use Capell\Core\Models\Page;
use Capell\Core\Support\Permissions\SiteAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class BuildDefaultSiteStatsAction
{
    use AsFake;
    use AsObject;

    private ?int $siteId = null;

    /**
     * @param  int|null  $siteId  Restricts every count to one site; a site the person cannot access yields zero counts.
     */
    public function handle(string $period = 'last_30_days', ?int $siteId = null, bool $scoped = false): SiteStatsData
    {
        $this->siteId = $siteId;
        [$rangeStart, $rangeEnd] = ResolveDashboardPeriodWindowsAction::run($period);

        // Genuine schedules only: sentinel drafts are not upcoming work.
        $pendingCount = $this->basePageQuery()->scheduled()->count();
        $expiredCount = $this->basePageQuery()->expired()->count();

        return new SiteStatsData(
            workQueueCount: $pendingCount + $expiredCount,
            publishedCount: $this->publishedWithin($rangeStart, $rangeEnd),
            sparklinePublished: $this->buildSparklinePublished($rangeStart, $rangeEnd),
            pendingCount: $pendingCount,
            expiredCount: $expiredCount,
            totalPagesCount: $this->basePageQuery()->count(),
            scoped: $scoped,
        );
    }

    private function publishedWithin(CarbonImmutable $rangeStart, CarbonImmutable $rangeEnd): int
    {
        return $this->basePageQuery()
            ->published()
            ->where(fn (Builder $query): Builder => $this->publishedMarkerWithin($query, $rangeStart, $rangeEnd))
            ->count();
    }

    /**
     * @return list<int>
     */
    private function buildSparklinePublished(CarbonImmutable $rangeStart, CarbonImmutable $rangeEnd): array
    {
        $bucketSeconds = max(1, (int) ($rangeStart->diffInSeconds($rangeEnd) / 7));
        $points = [];

        for ($bucket = 0; $bucket < 7; $bucket++) {
            $bucketStart = $rangeStart->addSeconds($bucket * $bucketSeconds);
            $bucketEnd = $rangeStart->addSeconds(($bucket + 1) * $bucketSeconds);

            $points[] = $this->publishedWithin($bucketStart, $bucketEnd);
        }

        return $points;
    }

    /**
     * @param  Builder<Page>  $query
     * @return Builder<Page>
     */
    private function publishedMarkerWithin(Builder $query, CarbonImmutable $rangeStart, CarbonImmutable $rangeEnd): Builder
    {
        return $query
            ->whereBetween($query->getModel()->qualifyColumn('visible_from'), [$rangeStart, $rangeEnd])
            ->orWhere(function (Builder $fallbackQuery) use ($rangeStart, $rangeEnd): void {
                $fallbackQuery
                    ->whereNull($fallbackQuery->getModel()->qualifyColumn('visible_from'))
                    ->whereBetween($fallbackQuery->getModel()->qualifyColumn('created_at'), [$rangeStart, $rangeEnd]);
            });
    }

    /**
     * @return Builder<Page>
     */
    private function basePageQuery(): Builder
    {
        /** @var Builder<Page> $query */
        $query = SiteAccess::current()->query(Page::class);

        if ($this->siteId !== null) {
            $query->where($query->getModel()->qualifyColumn('site_id'), $this->siteId);
        }

        return $query;
    }
}
