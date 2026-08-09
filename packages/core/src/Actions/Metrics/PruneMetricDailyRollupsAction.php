<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Metrics;

use Capell\Core\Models\MetricCollectionRun;
use Capell\Core\Models\MetricDailyRollup;
use Carbon\CarbonImmutable;

final class PruneMetricDailyRollupsAction
{
    public function execute(?int $days = null, bool $pretend = false): int
    {
        $days = max(30, min(3650, $days ?? (int) config('capell.analytics.daily_rollup_retention_days', 365)));
        $cutoff = CarbonImmutable::now('UTC')->subDays($days)->startOfDay();
        $rollups = MetricDailyRollup::query()->where('day', '<', $cutoff);

        if ($pretend) {
            return $rollups->count();
        }

        $count = $rollups->delete();
        MetricCollectionRun::query()->where('day', '<', $cutoff)->delete();

        return $count;
    }
}
