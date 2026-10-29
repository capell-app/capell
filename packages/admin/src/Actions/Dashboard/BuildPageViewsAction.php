<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Dashboard;

use Capell\Admin\Data\Dashboard\DashboardPageViewsData;
use Capell\Admin\Settings\AdminSettings;
use Capell\Admin\Support\Dashboard\DashboardSiteEvaluator;
use Capell\Core\Data\Metrics\MetricScopeData;
use Capell\Core\Enums\Metrics\MetricCollectionRunStatus;
use Capell\Core\Enums\Metrics\MetricPointState;
use Capell\Core\Enums\Metrics\MetricValueType;
use Capell\Core\Enums\MetricUnitEnum;
use Capell\Core\Models\Language;
use Capell\Core\Models\MetricDailyRollup;
use Capell\Core\Models\Site;
use Capell\Core\Support\Metrics\ActivityBucketsDailyMetricsCollector;
use Capell\Core\Support\Permissions\SiteAccess;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Throwable;

final class BuildPageViewsAction
{
    public function handle(Authenticatable $actor, ?int $siteId, ?string $language, int $days = 30): DashboardPageViewsData
    {
        if (! resolve(CanViewWorkFirstAnalyticsAction::class)->handle($actor, $siteId)) {
            return new DashboardPageViewsData('unauthorised', null, null, [], null, 0, 0);
        }

        if (! AdminSettings::instance()->analytics_collection_enabled) {
            return new DashboardPageViewsData('disconnected', null, null, [], null, 0, 0);
        }

        $period = resolve(BuildCompleteAnalyticsPeriodAction::class)->handle(CarbonImmutable::now('UTC'), $days, 'UTC');
        $evaluator = resolve(DashboardSiteEvaluator::class);
        $sites = SiteAccess::forActor($actor, acrossAssignedSites: true)->query(Site::class)
            ->enabled()
            ->with(['language', 'siteDomains.language'])
            ->whereIn('id', $evaluator->siteIds($actor, $siteId))
            ->get()
            ->filter(fn (Site $site): bool => $evaluator->allows($actor, (int) $site->getKey(), fn (): bool => resolve(CanViewWorkFirstAnalyticsAction::class)->handle($actor, (int) $site->getKey()) && Gate::forUser($actor)->allows('view', $site)));
        $scopes = [];
        foreach ($sites as $site) {
            foreach ($site->getAllLanguages() as $siteLanguage) {
                if ($siteLanguage instanceof Language && ($language === null || $siteLanguage->code === $language)) {
                    $scopes[MetricScopeData::siteLanguage($site->uuid, $siteLanguage->code, 'UTC')->key()] = (int) $site->getKey();
                }
            }
        }

        if ($scopes === []) {
            return new DashboardPageViewsData('insufficient', null, null, [], null, 0, $sites->count());
        }

        $scopeKeys = array_keys($scopes);
        sort($scopeKeys);
        $cacheKey = 'capell:dashboard:page-views:' . hash('sha256', json_encode([$actor::class, $actor->getAuthIdentifier(), $siteId, $language, $days, $scopeKeys], JSON_THROW_ON_ERROR));

        try {
            $rollups = SiteAccess::forActor($actor, acrossAssignedSites: true)->query(MetricDailyRollup::class)
                ->whereIn('site_id', $sites->modelKeys())
                ->where('owner_package', ActivityBucketsDailyMetricsCollector::OWNER_PACKAGE)
                ->where('collector_key', ActivityBucketsDailyMetricsCollector::COLLECTOR_KEY)
                ->where('metric_key', ActivityBucketsDailyMetricsCollector::PAGE_VIEWS_METRIC)
                ->whereIn('scope_key', array_keys($scopes))
                ->where('day', '>=', $period->previousStart->toDateString())
                ->where('day', '<', $period->end->toDateString())
                ->whereHas('collectionRun', fn (Builder $query): Builder => $query->where('status', MetricCollectionRunStatus::Completed->value)
                    ->whereColumn('metric_collection_runs.day', 'metric_daily_rollups.day'))
                ->get();
        } catch (QueryException $queryException) {
            report($queryException);
            try {
                $last = Cache::get($cacheKey);
            } catch (Throwable $cacheException) {
                report($cacheException);
                $last = null;
            }

            return $last instanceof DashboardPageViewsData
                && resolve(ValidateDashboardReportingPeriodAction::class)->handle($last->periodStart, $last->periodEnd, 'UTC', $days, 'stale')
                ? new DashboardPageViewsData('stale', $last->current, $last->previous, $last->trend, $last->lastUpdated, $last->sitesCovered, $last->sitesRequested, $last->periodStart, $last->periodEnd)
                : new DashboardPageViewsData('failed', null, null, [], null, 0, $sites->count());
        }

        $definition = collect((new ActivityBucketsDailyMetricsCollector)->definitions())
            ->first(fn ($item): bool => $item->identity->metricKey === ActivityBucketsDailyMetricsCollector::PAGE_VIEWS_METRIC);
        $expectedHash = $definition?->semanticHash();
        $points = [];
        $updated = null;
        foreach ($rollups as $rollup) {
            if ($rollup->definition_hash !== $expectedHash) {
                continue;
            }

            if ($rollup->unit !== MetricUnitEnum::Count) {
                continue;
            }

            if ($rollup->value_type !== MetricValueType::Integer) {
                continue;
            }

            if (! in_array($rollup->point_state, [MetricPointState::Present, MetricPointState::Zero], true)) {
                continue;
            }

            if ((int) $rollup->site_id !== $scopes[$rollup->scope_key]) {
                continue;
            }

            if ($rollup->timezone !== 'UTC') {
                continue;
            }

            if ($rollup->day_starts_at !== '00:00:00') {
                continue;
            }

            if ($rollup->value === null) {
                continue;
            }

            if (! ctype_digit((string) $rollup->value)) {
                continue;
            }

            if (filter_var($rollup->value, FILTER_VALIDATE_INT) === false) {
                continue;
            }

            if ($rollup->point_state === MetricPointState::Zero && (int) $rollup->value !== 0) {
                continue;
            }

            $day = $rollup->day->toDateString();
            $points[$day][$rollup->scope_key] = (int) $rollup->value;
            $stamp = $rollup->updated_at?->toIso8601String();
            if ($stamp !== null && ($updated === null || $stamp > $updated)) {
                $updated = $stamp;
            }
        }

        $current = $this->completeDays($points, array_keys($scopes), $period->start, $days);
        $previous = $this->completeDays($points, array_keys($scopes), $period->previousStart, $days);
        $scopesBySite = [];
        foreach ($scopes as $scopeKey => $scopeSiteId) {
            $scopesBySite[$scopeSiteId][] = $scopeKey;
        }

        $coveredSites = 0;
        foreach ($scopesBySite as $siteScopes) {
            if ($this->completeDays($points, $siteScopes, $period->start, $days) !== null) {
                $coveredSites++;
            }
        }

        $result = new DashboardPageViewsData(
            status: $current === null ? 'insufficient' : 'available',
            current: $current === null ? null : array_sum(array_column($current, 'count')),
            previous: $previous === null ? null : array_sum(array_column($previous, 'count')),
            trend: $current ?? [],
            lastUpdated: $updated,
            sitesCovered: $coveredSites,
            sitesRequested: $sites->count(),
            periodStart: $period->start->toDateString(),
            periodEnd: $period->end->toDateString(),
        );
        if ($result->status === 'available' && $result->lastUpdated !== null) {
            try {
                Cache::forever($cacheKey, $result);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $result;
    }

    /**
     * @param  array<string, array<string, int>>  $points
     * @param  list<string>  $scopes
     * @return list<array{day: string, count: int}>|null
     */
    private function completeDays(array $points, array $scopes, CarbonImmutable $start, int $days): ?array
    {
        $result = [];
        for ($offset = 0; $offset < $days; $offset++) {
            $day = $start->addDays($offset)->toDateString();
            $values = $points[$day] ?? [];
            if (array_diff($scopes, array_keys($values)) !== []) {
                return null;
            }

            $result[] = ['day' => $day, 'count' => array_sum($values)];
        }

        return $result;
    }
}
