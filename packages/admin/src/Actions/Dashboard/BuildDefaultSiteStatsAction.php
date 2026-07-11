<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Dashboard;

use Capell\Admin\Data\Dashboard\SiteStatsData;
use Capell\Admin\Support\SiteScope;
use Capell\Core\Models\Page;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Lorisleiva\Actions\Concerns\AsObject;

final class BuildDefaultSiteStatsAction
{
    use AsObject;

    public function handle(string $period = 'last_30_days'): SiteStatsData
    {
        [$rangeStart, $rangeEnd] = $this->resolveDateRange($period);

        $pendingCount = $this->basePageQuery()->pending()->count();
        $expiredCount = $this->basePageQuery()->expired()->count();

        return new SiteStatsData(
            workQueueCount: $pendingCount + $expiredCount,
            publishedCount: $this->publishedWithin($rangeStart, $rangeEnd),
            sparklinePublished: $this->buildSparklinePublished($rangeStart, $rangeEnd),
            pendingCount: $pendingCount,
            expiredCount: $expiredCount,
            totalPagesCount: $this->basePageQuery()->count(),
        );
    }

    /** @return array{CarbonImmutable, CarbonImmutable} */
    private function resolveDateRange(string $period): array
    {
        $now = CarbonImmutable::now();

        return match ($period) {
            'today' => [$now->startOfDay(), $now->endOfDay()],
            'this_week' => [$now->startOfWeek(), $now->endOfWeek()],
            'this_month' => [$now->startOfMonth(), $now->endOfMonth()],
            'this_year' => [$now->startOfYear(), $now->endOfYear()],
            default => [$now->subDays(30)->startOfDay(), $now->endOfDay()],
        };
    }

    private function publishedWithin(CarbonImmutable $rangeStart, CarbonImmutable $rangeEnd): int
    {
        return $this->basePageQuery()
            ->publishedDate()
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
        $query = SiteScope::applyForCurrentActor(Page::query());

        return $query;
    }
}
