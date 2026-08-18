<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Activity;

use Capell\Core\Contracts\ActivitySettingsReader;
use Capell\Core\Enums\Metrics\MetricCollectionRunStatus;
use Capell\Core\Models\ActivityVisitor;
use Capell\Core\Models\MetricCollectionRun;
use Capell\Core\Support\Metrics\ActivityVisitorsDailyMetricsCollector;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use RuntimeException;

/**
 * Visitor-day rows have their own retention because the dashboard compares the
 * last seven days against the seven before them, and multi-day unique counts
 * cannot be recovered from daily rollups. This cannot reuse the activity bucket
 * prune, whose window is clamped to seven days.
 */
final class PruneActivityVisitorsAction
{
    public function __construct(private readonly ActivitySettingsReader $settings) {}

    public function execute(?int $days = null, bool $pretend = false): int
    {
        $days = max(14, min(400, $days ?? $this->settings->visitorRetentionDays()));
        $cutoff = CarbonImmutable::now('UTC')->subDays($days)->toDateString();
        $query = ActivityVisitor::query()->whereDate('day', '<', $cutoff);

        if ($pretend) {
            return $query->count();
        }

        $daysWithRows = $query
            ->distinct()
            ->pluck('day')
            ->map($this->normalizeDay(...))
            ->values()
            ->all();

        if ($daysWithRows !== []) {
            $completedDays = array_values(array_filter(
                $daysWithRows,
                fn (string $day): bool => MetricCollectionRun::query()
                    ->where('owner_package', ActivityVisitorsDailyMetricsCollector::OWNER_PACKAGE)
                    ->where('collector_key', ActivityVisitorsDailyMetricsCollector::COLLECTOR_KEY)
                    ->where('status', MetricCollectionRunStatus::Completed)
                    ->whereDate('day', $day)
                    ->exists(),
            ));

            throw_if(array_diff($daysWithRows, $completedDays) !== [], RuntimeException::class, 'Activity visitors were not pruned because one or more expired days lack a completed daily rollup.');
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
