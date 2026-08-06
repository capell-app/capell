<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Activity;

use Capell\Core\Contracts\ActivitySettingsReader;
use Capell\Core\Enums\Metrics\MetricCollectionRunStatus;
use Capell\Core\Models\ActivityBucket;
use Capell\Core\Models\MetricCollectionRun;
use Capell\Core\Support\Metrics\ActivityBucketsDailyMetricsCollector;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class PruneActivityBucketsAction
{
    public function __construct(private readonly ActivitySettingsReader $settings) {}

    public function execute(?int $days = null, bool $pretend = false): int
    {
        $days = max(1, min(7, $days ?? $this->settings->retentionDays()));
        $cutoff = CarbonImmutable::now('UTC')->subDays($days);
        $query = ActivityBucket::query()->where('bucket_started_at', '<', $cutoff);

        if ($pretend) {
            return $query->count();
        }

        $daysWithRows = $query
            ->select(DB::raw('DATE(bucket_started_at) AS activity_day'))
            ->distinct()
            ->pluck('activity_day')
            ->map($this->normalizeDay(...))
            ->values()
            ->all();

        if ($daysWithRows !== []) {
            $completedDays = array_values(array_filter(
                $daysWithRows,
                fn (string $day): bool => MetricCollectionRun::query()
                    ->where('owner_package', ActivityBucketsDailyMetricsCollector::OWNER_PACKAGE)
                    ->where('collector_key', ActivityBucketsDailyMetricsCollector::COLLECTOR_KEY)
                    ->where('status', MetricCollectionRunStatus::Completed)
                    ->whereDate('day', $day)
                    ->exists(),
            ));

            if (array_diff($daysWithRows, $completedDays) !== []) {
                throw new RuntimeException('Activity buckets were not pruned because one or more expired days lack a completed daily rollup.');
            }
        }

        return $query->delete();
    }

    private function normalizeDay(mixed $day): string
    {
        return $day instanceof DateTimeInterface
            ? $day->format('Y-m-d')
            : substr((string) $day, 0, 10);
    }
}
