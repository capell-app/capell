<?php

declare(strict_types=1);

namespace Capell\Core\Actions\HostMonitor;

use Capell\Core\Data\Diagnostics\HostLoadTrendData;
use Capell\Core\Models\HostLoadRollup;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Schema;
use Throwable;

/** Summarises one server's hourly rollups over a number of days, or returns null when there is no history yet. */
final readonly class HostLoadTrendAction
{
    public function handle(string $node, int $days): ?HostLoadTrendData
    {
        try {
            if (! Schema::hasTable((new HostLoadRollup)->getTable())) {
                return null;
            }

            $rows = HostLoadRollup::query()
                ->where('node', $node)
                ->where('hour_start', '>=', now()->getTimestamp() - max(1, $days) * 86400)
                ->where('samples', '>', 0)
                ->orderBy('hour_start')
                ->get();
        } catch (Throwable $throwable) {
            report($throwable);

            return null;
        }

        if ($rows->isEmpty()) {
            return null;
        }

        $peak = $rows->sortByDesc(static fn (HostLoadRollup $row): float => $row->load5_peak / max(1, $row->cores))->first();
        $samples = $rows->sum('samples');
        $ratioSum = $rows->sum(static fn (HostLoadRollup $row): float => $row->load5_sum / max(1, $row->cores));
        $memory = $rows->pluck('memory_available_min_percent')->filter(static fn (mixed $value): bool => is_float($value));
        $swap = $rows->pluck('swap_used_peak_percent')->filter(static fn (mixed $value): bool => is_float($value));
        $queue = $rows->pluck('queue_depth_peak')->filter(static fn (mixed $value): bool => is_int($value));

        $tasks = [];

        foreach ($rows as $row) {
            foreach ($row->busiest_tasks ?? [] as $task) {
                $tasks[$task['command']] = max($tasks[$task['command']] ?? 0.0, (float) $task['seconds']);
            }
        }

        arsort($tasks);
        $busiest = [];

        foreach (array_slice($tasks, 0, 3, true) as $command => $seconds) {
            $busiest[] = ['command' => (string) $command, 'seconds' => $seconds];
        }

        return new HostLoadTrendData(
            days: $days,
            hours: $rows->count(),
            cores: $peak instanceof HostLoadRollup ? max(1, $peak->cores) : 1,
            averageRatio: $samples > 0 ? $ratioSum / $samples : 0.0,
            peakRatio: $peak instanceof HostLoadRollup ? $peak->load5_peak / max(1, $peak->cores) : 0.0,
            peakAt: $peak instanceof HostLoadRollup ? $peak->hour_start : 0,
            busiestWindow: $this->busiestWindow($rows->values()->all()),
            lowestMemoryAvailablePercent: $memory->isEmpty() ? null : (float) $memory->min(),
            peakSwapUsedPercent: $swap->isEmpty() ? null : (float) $swap->max(),
            peakQueueDepth: $queue->isEmpty() ? null : (int) $queue->max(),
            busiestTasks: $busiest,
        );
    }

    /**
     * Average load ratio for each clock hour across the period, then the two consecutive hours that are busiest.
     *
     * @param  array<int, HostLoadRollup>  $rows
     * @return array{from: int, to: int, ratio: float}|null
     */
    private function busiestWindow(array $rows): ?array
    {
        $sum = array_fill(0, 24, 0.0);
        $count = array_fill(0, 24, 0);

        foreach ($rows as $row) {
            $hour = Date::createFromTimestamp($row->hour_start, (string) config('app.timezone', 'UTC'))->hour;
            $sum[$hour] += $row->load5_sum / max(1, $row->cores);
            $count[$hour] += $row->samples;
        }

        $best = null;

        for ($hour = 0; $hour < 24; $hour++) {
            $next = ($hour + 1) % 24;
            // A window needs readings for both of its hours, or an empty hour would pose as a quiet one.
            if ($count[$hour] === 0) {
                continue;
            }

            if ($count[$next] === 0) {
                continue;
            }

            $samples = $count[$hour] + $count[$next];

            $ratio = ($sum[$hour] + $sum[$next]) / $samples;

            if ($best === null || $ratio > $best['ratio']) {
                $best = ['from' => $hour, 'to' => ($hour + 2) % 24, 'ratio' => $ratio];
            }
        }

        return $best;
    }
}
