<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Metrics;

use Capell\Core\Data\Metrics\MetricDefinitionData;
use Capell\Core\Data\Metrics\MetricScopeData;
use Capell\Core\Data\Metrics\MetricValueData;
use Capell\Core\Enums\Metrics\MetricCollectionRunStatus;
use Capell\Core\Enums\Metrics\MetricPointState;
use Capell\Core\Models\MetricCollectionRun;
use Capell\Core\Models\MetricDailyRollup;
use Capell\Core\Models\MetricEvent;
use Capell\Core\Support\Metrics\MetricEventRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use RuntimeException;

final class RollupMetricEventsAction
{
    public function __construct(
        private readonly ConnectionInterface $database,
        private readonly MetricEventRegistry $registry,
        private readonly StoreMetricCollectionRunAction $storeRun,
        private readonly StoreMetricDailyRollupAction $storeRollup,
    ) {}

    public function handlePending(): int
    {
        $today = CarbonImmutable::now('UTC')->startOfDay();
        $days = MetricEvent::query()
            ->where('occurred_at', '<', $today)
            ->selectRaw('DATE(occurred_at) AS event_day')
            ->distinct()
            ->orderBy('event_day')
            ->pluck('event_day');

        return $days->sum(fn (mixed $day): int => $this->handle((string) $day));
    }

    public function handle(?string $day = null): int
    {
        $resolvedDay = $this->resolveDay($day);
        $start = $resolvedDay->startOfDay();
        $end = $start->addDay();

        return $this->database->transaction(function () use ($resolvedDay, $start, $end): int {
            $snapshotId = MetricEvent::query()
                ->where('occurred_at', '>=', $start)
                ->where('occurred_at', '<', $end)
                ->max('id');

            if ($snapshotId === null) {
                return 0;
            }

            /** @var Collection<int, MetricEvent> $events */
            $events = MetricEvent::query()
                ->where('occurred_at', '>=', $start)
                ->where('occurred_at', '<', $end)
                ->where('id', '<=', $snapshotId)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($events->isEmpty()) {
                return 0;
            }

            /** @var array<string, MetricDefinitionData> $definitions */
            $definitions = [];

            foreach ($events as $event) {
                $definition = $this->registry->definition($event->metric_key);

                if ($definition->identity->ownerPackage !== $event->owner_package
                    || $definition->identity->collectorKey !== $event->collector_key
                    || $definition->semanticHash() !== $event->definition_hash) {
                    throw new RuntimeException('Metric event definition is missing or has drifted.');
                }

                $definitions[$event->metric_key] = $definition;
            }

            $eventsByCollector = $events->groupBy(
                static fn (MetricEvent $event): string => $event->owner_package . "\0" . $event->collector_key,
            );

            foreach ($eventsByCollector as $collectorEvents) {
                $this->rollupCollector($resolvedDay->toDateString(), $collectorEvents, $definitions);
            }

            MetricEvent::query()->whereKey($events->modelKeys())->delete();

            return $events->count();
        });
    }

    /**
     * @param  Collection<int, MetricEvent>  $events
     * @param  array<string, MetricDefinitionData>  $definitions
     */
    private function rollupCollector(string $day, Collection $events, array $definitions): void
    {
        /** @var MetricEvent $first */
        $first = $events->firstOrFail();
        $definitionHashes = $events
            ->map(static fn (MetricEvent $event): string => $event->definition_hash)
            ->unique()
            ->sort()
            ->values()
            ->all();
        $definitionSetHash = hash('sha256', json_encode($definitionHashes, JSON_THROW_ON_ERROR));
        $startedAt = CarbonImmutable::now('UTC');
        $run = $this->storeRun->execute(
            day: $day,
            ownerPackage: $first->owner_package,
            collectorKey: $first->collector_key,
            definitionHash: $definitionSetHash,
            status: MetricCollectionRunStatus::Started,
            startedAt: $startedAt,
        );

        $groups = $events->groupBy(
            static fn (MetricEvent $event): string => $event->metric_key . "\0" . $event->scope_key,
        );
        $checksumPayload = [];

        foreach ($groups as $group) {
            /** @var MetricEvent $event */
            $event = $group->firstOrFail();
            $definition = $definitions[$event->metric_key];
            $increment = 0;

            foreach ($group as $sample) {
                $weightedValue = $sample->value * $sample->weight;

                if (! is_int($weightedValue) || ($weightedValue > 0 && $increment > PHP_INT_MAX - $weightedValue)) {
                    throw new RuntimeException('Metric event weighted sum exceeds the integer range.');
                }

                $increment += $weightedValue;
            }

            $scope = new MetricScopeData(
                type: $event->scope_type,
                timezone: $event->timezone,
                dayStartsAt: $event->day_starts_at,
                siteUuid: $event->site_uuid,
                language: $event->language,
            );
            $rollup = MetricDailyRollup::query()
                ->where('day', $day)
                ->where('owner_package', $event->owner_package)
                ->where('collector_key', $event->collector_key)
                ->where('metric_key', $event->metric_key)
                ->where('scope_key', $event->scope_key)
                ->lockForUpdate()
                ->first();

            if ($rollup === null) {
                $rollup = $this->storeRollup->execute(
                    run: $run,
                    definition: $definition,
                    day: $day,
                    scope: $scope,
                    state: MetricPointState::Present,
                    value: MetricValueData::integer($increment),
                    siteId: $event->site_id,
                );
            } else {
                $existingValue = filter_var($rollup->value, FILTER_VALIDATE_INT);

                if ($existingValue === false || $existingValue > PHP_INT_MAX - $increment) {
                    throw new RuntimeException('Metric daily rollup exceeds the integer range.');
                }

                $value = $existingValue + $increment;
                $rollup->fill([
                    'metric_collection_run_id' => $run->getKey(),
                    'definition_hash' => $definition->semanticHash(),
                    'site_id' => $event->site_id,
                    'value' => (string) $value,
                    'point_state' => $value === 0 ? MetricPointState::Zero : MetricPointState::Present,
                ])->save();
            }

            $checksumPayload[] = [
                'metric' => $event->metric_key,
                'scope' => $event->scope_key,
                'increment' => $increment,
                'event_ids' => $group->modelKeys(),
            ];
        }

        $this->storeRun->execute(
            day: $day,
            ownerPackage: $first->owner_package,
            collectorKey: $first->collector_key,
            definitionHash: $definitionSetHash,
            status: MetricCollectionRunStatus::Completed,
            startedAt: $startedAt,
            completedAt: CarbonImmutable::now('UTC'),
            sourceWatermark: 'metric-event-id:' . $events->max('id'),
            sourceChecksum: hash('sha256', json_encode($checksumPayload, JSON_THROW_ON_ERROR)),
            run: $run,
        );
    }

    private function resolveDay(?string $day): CarbonImmutable
    {
        if ($day === null) {
            return CarbonImmutable::now('UTC')->subDay()->startOfDay();
        }

        $resolved = CarbonImmutable::createFromFormat('!Y-m-d', $day, 'UTC');

        if ($resolved === false || $resolved->format('Y-m-d') !== $day) {
            throw new InvalidArgumentException('Metric rollup day must use Y-m-d.');
        }

        if ($resolved->isAfter(CarbonImmutable::now('UTC')->startOfDay())) {
            throw new InvalidArgumentException('Metric rollup day cannot be in the future.');
        }

        return $resolved;
    }
}
