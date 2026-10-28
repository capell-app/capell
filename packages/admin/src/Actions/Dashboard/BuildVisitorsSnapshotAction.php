<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Dashboard;

use Capell\Admin\Contracts\Dashboard\DashboardVisitorsProvider;
use Capell\Admin\Data\Dashboard\DashboardVisitorsData;
use Capell\Admin\Support\Dashboard\DashboardSiteEvaluator;
use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Capell\Core\Support\Permissions\SiteAccess;
use DateTimeZone;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Throwable;

final class BuildVisitorsSnapshotAction
{
    public function handle(Authenticatable $actor, ?int $siteId, ?string $language, int $days): ?DashboardVisitorsData
    {
        $mapping = config('capell-admin.dashboard.work_first.analytics.visitors', []);
        if (! resolve(CanViewWorkFirstAnalyticsAction::class)->handle($actor, $siteId) || $siteId === null || $siteId < 1
            || $days < 1 || $days > 365 || ! is_array($mapping) || ($mapping['enabled'] ?? false) !== true
            || ! is_string($mapping['measure'] ?? null) || $mapping['measure'] === ''
            || ! is_string($mapping['source'] ?? null) || $mapping['source'] === '') {
            return null;
        }

        $evaluator = resolve(DashboardSiteEvaluator::class);
        $site = SiteAccess::forActor($actor, acrossAssignedSites: true)->query(Site::class)->find($siteId);
        if (! $site instanceof Site || ! $evaluator->allows($actor, $siteId, fn (): bool => Gate::forUser($actor)->allows('view', $site))) {
            return null;
        }

        $site->loadMissing(['language', 'siteDomains.language']);
        $languages = $site->getAllLanguages()->filter(fn (mixed $item): bool => $item instanceof Language)
            ->map(fn (Language $item): string => $item->code)->sort()->values()->all();
        if ($language !== null && ! in_array($language, $languages, true)) {
            return null;
        }

        $cacheKey = 'capell:dashboard:visitors:' . hash('sha256', json_encode([$actor::class, $actor->getAuthIdentifier(), $siteId, $site->uuid, $language, $languages, $days, $mapping], JSON_THROW_ON_ERROR));
        try {
            $data = $evaluator->run($actor, $siteId, fn (): DashboardVisitorsData => resolve(DashboardVisitorsProvider::class)->build($actor, $siteId, $language, $days));
        } catch (Throwable $throwable) {
            report($throwable);

            return $this->staleOrFailed($cacheKey, $days);
        }

        if ($data->status === 'failed') {
            return $this->staleOrFailed($cacheKey, $days);
        }

        if ($data->status === 'disconnected') {
            return $data;
        }

        if (! in_array($data->status, ['available', 'stale', 'insufficient'], true)
            || $data->measure !== $mapping['measure'] || $data->source !== $mapping['source']
            || ! in_array($data->timezone, DateTimeZone::listIdentifiers(), true)
            || ($data->current !== null && $data->current < 0) || ($data->previous !== null && $data->previous < 0)
            || (in_array($data->status, ['available', 'stale'], true) && $data->current === null)
            || (in_array($data->status, ['available', 'stale'], true) && $data->lastUpdated === null)
            || (in_array($data->status, ['available', 'stale'], true) && ! resolve(ValidateDashboardReportingPeriodAction::class)->handle($data->periodStart, $data->periodEnd, $data->timezone, $days, $data->status))) {
            return new DashboardVisitorsData('insufficient', '', '', '', null, null, null);
        }

        if ($data->status === 'available') {
            try {
                Cache::forever($cacheKey, $data);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $data;
    }

    private function staleOrFailed(string $cacheKey, int $days): DashboardVisitorsData
    {
        try {
            $last = Cache::get($cacheKey);
        } catch (Throwable $throwable) {
            report($throwable);
            $last = null;
        }

        return $last instanceof DashboardVisitorsData && $last->lastUpdated !== null
            && resolve(ValidateDashboardReportingPeriodAction::class)->handle($last->periodStart, $last->periodEnd, $last->timezone, $days, 'stale')
            ? new DashboardVisitorsData('stale', $last->measure, $last->source, $last->timezone, $last->current, $last->previous, $last->lastUpdated, $last->periodStart, $last->periodEnd)
            : new DashboardVisitorsData('failed', '', '', '', null, null, null);
    }
}
