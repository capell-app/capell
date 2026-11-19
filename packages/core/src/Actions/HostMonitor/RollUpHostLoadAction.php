<?php

declare(strict_types=1);

namespace Capell\Core\Actions\HostMonitor;

use Capell\Core\Data\Diagnostics\HostSampleData;
use Capell\Core\Models\HostLoadRollup;
use Capell\Core\Support\HostMonitor\HostMonitorStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Folds this server's cached readings into hourly database rows. Each row
 * remembers the newest reading it has counted, so running this every few
 * minutes, or twice at once, never counts a reading twice. Runs inside the
 * scheduler process and never starts another one.
 */
final readonly class RollUpHostLoadAction
{
    private const int TASKS_KEPT = 3;

    public function __construct(private HostMonitorStore $store) {}

    /** @return int Readings added to the rollups. */
    public function handle(): int
    {
        if (config('capell.health.host.enabled', true) !== true || config('capell.health.host.history.enabled', true) !== true) {
            return 0;
        }

        try {
            return $this->rollUp();
        } catch (Throwable $throwable) {
            // History is a convenience: it must not fail the scheduler run it is part of.
            report($throwable);

            return 0;
        }
    }

    private function rollUp(): int
    {
        if (! Schema::hasTable((new HostLoadRollup)->getTable())) {
            return 0;
        }

        $node = $this->store->node();
        $lock = Cache::lock('capell:host-load-rollup:' . $node, 120);

        if (! $lock->get()) {
            return 0;
        }

        try {
            return $this->fold($node);
        } finally {
            $lock->release();
        }
    }

    private function fold(string $node): int
    {
        $counted = (int) HostLoadRollup::query()->where('node', $node)->max('last_sample_at');
        $fresh = array_values(array_filter(
            $this->store->samples($node),
            static fn (HostSampleData $sample): bool => $sample->takenAt > $counted,
        ));

        $byHour = [];

        foreach ($fresh as $sample) {
            $byHour[$sample->takenAt - $sample->takenAt % 3600][] = $sample;
        }

        DB::transaction(function () use ($node, $byHour): void {
            foreach ($byHour as $hourStart => $samples) {
                $this->merge($node, $hourStart, $samples);
            }
        });

        return count($fresh);
    }

    /** @param  non-empty-list<HostSampleData>  $samples */
    private function merge(string $node, int $hourStart, array $samples): void
    {
        $row = HostLoadRollup::query()->firstOrNew(['node' => $node, 'hour_start' => $hourStart]);

        $memory = array_filter(array_map(static fn (HostSampleData $sample): ?float => $sample->memoryAvailablePercent(), $samples), is_float(...));
        $swap = array_filter(array_map(static fn (HostSampleData $sample): ?float => $sample->swapUsedPercent(), $samples), is_float(...));
        $queue = array_filter(array_map(static fn (HostSampleData $sample): ?int => $sample->queueDepth, $samples), is_int(...));
        $latest = $samples[array_key_last($samples)];

        $row->fill([
            'cores' => max(1, $latest->cores),
            'samples' => (int) $row->samples + count($samples),
            'load5_sum' => (float) $row->load5_sum + array_sum(array_map(static fn (HostSampleData $sample): float => $sample->load5, $samples)),
            'load5_peak' => max((float) $row->load5_peak, ...array_map(static fn (HostSampleData $sample): float => $sample->load5, $samples)),
            'memory_available_min_percent' => $this->smallest($row->memory_available_min_percent, $memory),
            'swap_used_peak_percent' => $this->largest($row->swap_used_peak_percent, $swap),
            'queue_depth_peak' => $this->largest($row->queue_depth_peak, $queue),
            'busiest_tasks' => $this->busiestTasks($row->busiest_tasks ?? [], $samples),
            'last_sample_at' => max((int) $row->last_sample_at, ...array_map(static fn (HostSampleData $sample): int => $sample->takenAt, $samples)),
        ]);
        $row->save();
    }

    /** @param  array<int, float>  $values */
    private function smallest(int|float|null $stored, array $values): int|float|null
    {
        return $values === [] ? $stored : min([...$values, ...($stored === null ? [] : [$stored])]);
    }

    /** @param  array<int, int|float>  $values */
    private function largest(int|float|null $stored, array $values): int|float|null
    {
        return $values === [] ? $stored : max([...$values, ...($stored === null ? [] : [$stored])]);
    }

    /**
     * @param  list<array{command: string, seconds: float}>  $stored
     * @param  non-empty-list<HostSampleData>  $samples
     * @return list<array{command: string, seconds: float}>
     */
    private function busiestTasks(array $stored, array $samples): array
    {
        $longest = [];

        foreach ([$stored, ...array_map(static fn (HostSampleData $sample): array => $sample->slowestSchedulerTasks, $samples)] as $tasks) {
            foreach ($tasks as $task) {
                $longest[$task['command']] = max($longest[$task['command']] ?? 0.0, (float) $task['seconds']);
            }
        }

        arsort($longest);

        $kept = [];

        foreach (array_slice($longest, 0, self::TASKS_KEPT, true) as $command => $seconds) {
            $kept[] = ['command' => (string) $command, 'seconds' => round($seconds, 1)];
        }

        return $kept;
    }
}
