<?php

declare(strict_types=1);

namespace Capell\Core\Support\HostMonitor;

use Capell\Core\Data\Diagnostics\HostSampleData;
use Illuminate\Contracts\Cache\Repository;
use Throwable;

/**
 * Keeps the recent readings, alert state and scheduler figures for each node in
 * the default cache store, so a shared store shows every server on one page.
 * Nothing here is authoritative history: losing the cache loses only the last
 * hour of readings, and a failing store never stops a request or a scheduler run.
 */
final readonly class HostMonitorStore
{
    private const string PREFIX = 'capell:host-monitor:';

    private const int RETENTION_SECONDS = 86400;

    /** Long enough to outlast a task that runs once a year, so even a rarely run task is released. */
    private const int DEFERRAL_SECONDS = 400 * 86400;

    public function __construct(private Repository $cache) {}

    public function node(): string
    {
        $configured = config('capell.node_id');

        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        $hostname = gethostname();

        return is_string($hostname) && $hostname !== '' ? $hostname : 'unknown';
    }

    public function pushSample(HostSampleData $sample): void
    {
        $limit = max(1, (int) config('capell.health.host.history_samples', 60));
        $samples = array_slice([...$this->rawSamples($sample->node), $sample->toArray()], -$limit);

        $this->put('samples:' . $sample->node, $samples);

        // Read-modify-write: two servers writing in the same instant can drop each other's entry, which the next minute's reading restores.
        $nodes = $this->rawNodes();
        $nodes[$sample->node] = $sample->takenAt;
        $this->put('nodes', $nodes);
    }

    /** @return list<HostSampleData> */
    public function samples(?string $node = null): array
    {
        $samples = [];

        foreach ($this->rawSamples($node ?? $this->node()) as $raw) {
            try {
                $samples[] = HostSampleData::from($raw);
            } catch (Throwable) {
                // A reading written by an older release is skipped rather than shown wrongly.
            }
        }

        return $samples;
    }

    /** @return list<string> Nodes that reported within the last day. */
    public function nodes(): array
    {
        $cutoff = now()->getTimestamp() - self::RETENTION_SECONDS;

        // A node named with digits only becomes an integer array key, so cast it back.
        return array_map(
            static fn (int|string $node): string => (string) $node,
            array_keys(array_filter(
                $this->rawNodes(),
                static fn (mixed $seenAt): bool => is_int($seenAt) && $seenAt >= $cutoff,
            )),
        );
    }

    /** @return array{status: string, since: int, notified_at: int|null} */
    public function state(string $node): array
    {
        $state = $this->get('state:' . $node);

        if (is_array($state) && is_string($state['status'] ?? null) && is_int($state['since'] ?? null)) {
            return [
                'status' => $state['status'],
                'since' => $state['since'],
                'notified_at' => is_int($state['notified_at'] ?? null) ? $state['notified_at'] : null,
            ];
        }

        return ['status' => 'ok', 'since' => now()->getTimestamp(), 'notified_at' => null];
    }

    /** @param  array{status: string, since: int, notified_at: int|null}  $state */
    public function putState(string $node, array $state): void
    {
        $this->put('state:' . $node, $state);
    }

    /** @return array{at: float, ticks: array<int, int>}|null */
    public function cpuSnapshot(string $node): ?array
    {
        $snapshot = $this->get('cpu:' . $node);

        if (! is_array($snapshot) || ! is_float($snapshot['at'] ?? null) && ! is_int($snapshot['at'] ?? null) || ! is_array($snapshot['ticks'] ?? null)) {
            return null;
        }

        $ticks = [];

        foreach ($snapshot['ticks'] as $pid => $count) {
            if (is_int($pid) && is_int($count)) {
                $ticks[$pid] = $count;
            }
        }

        return ['at' => (float) $snapshot['at'], 'ticks' => $ticks];
    }

    /** @param  array{at: float, ticks: array<int, int>}  $snapshot */
    public function putCpuSnapshot(string $node, array $snapshot): void
    {
        $this->put('cpu:' . $node, $snapshot);
    }

    /** @return array{minute: string, tasks: int, processes: int, cpu_seconds: float, wall_seconds: float, slowest: list<array{command: string, seconds: float}>}|null */
    public function schedulerRun(string $node, string $minute): ?array
    {
        $run = $this->get('scheduler:' . $node . ':' . $minute);

        if (! is_array($run) || ! is_int($run['tasks'] ?? null) || ! is_int($run['processes'] ?? null)) {
            return null;
        }

        $slowest = [];

        foreach (is_array($run['slowest'] ?? null) ? $run['slowest'] : [] as $entry) {
            if (is_array($entry) && is_string($entry['command'] ?? null) && (is_float($entry['seconds'] ?? null) || is_int($entry['seconds'] ?? null))) {
                $slowest[] = ['command' => $entry['command'], 'seconds' => (float) $entry['seconds']];
            }
        }

        return [
            'minute' => $minute,
            'tasks' => $run['tasks'],
            'processes' => $run['processes'],
            'cpu_seconds' => is_numeric($run['cpu_seconds'] ?? null) ? (float) $run['cpu_seconds'] : 0.0,
            'wall_seconds' => is_numeric($run['wall_seconds'] ?? null) ? (float) $run['wall_seconds'] : 0.0,
            'slowest' => $slowest,
        ];
    }

    /** @param  array{tasks: int, processes: int, cpu_seconds: float, wall_seconds: float, slowest: list<array{command: string, seconds: float}>}  $run */
    public function putSchedulerRun(string $node, string $minute, array $run): void
    {
        $this->put('scheduler:' . $node . ':' . $minute, $run, 3600);
    }

    /** Unix time the named task was first deferred in the current busy spell, or null. */
    public function deferredSince(string $task): ?int
    {
        $since = $this->get('deferred:' . $this->taskKey($task));

        // A shared cache such as Redis returns numbers as strings.
        return is_int($since) || (is_string($since) && ctype_digit($since)) ? (int) $since : null;
    }

    public function markDeferred(string $task, int $at): void
    {
        $this->put('deferred:' . $this->taskKey($task), $at, self::DEFERRAL_SECONDS);
    }

    public function clearDeferred(string $task): void
    {
        try {
            $this->cache->forget(self::PREFIX . 'deferred:' . $this->taskKey($task));
        } catch (Throwable) {
            // Monitoring must never break the work it observes.
        }
    }

    /**
     * Unix time a listed task first skipped a run in the current busy spell and has not yet run since, or null.
     * A task that runs on one server only keeps one shared record, so every node sees the same state.
     */
    public function catchUpPendingSince(string $task, bool $shared = false): ?int
    {
        $since = $this->get('catchup-pending:' . $this->taskKey($task, $shared));

        return is_int($since) || (is_string($since) && ctype_digit($since)) ? (int) $since : null;
    }

    public function markCatchUpPending(string $task, int $at, bool $shared = false): void
    {
        $this->put('catchup-pending:' . $this->taskKey($task, $shared), $at, self::DEFERRAL_SECONDS);
    }

    public function clearCatchUpPending(string $task, bool $shared = false): void
    {
        try {
            $this->cache->forget(self::PREFIX . 'catchup-pending:' . $this->taskKey($task, $shared));
        } catch (Throwable) {
            // Monitoring must never break the work it observes.
        }
    }

    /** Unix time a listed task last finished, recorded like the pending marker: shared for onOneServer tasks. */
    public function lastRunAt(string $task, bool $shared = false): ?int
    {
        $at = $this->get('last-run:' . $this->taskKey($task, $shared));

        return is_int($at) || (is_string($at) && ctype_digit($at)) ? (int) $at : null;
    }

    public function markRan(string $task, int $at, bool $shared = false): void
    {
        $this->put('last-run:' . $this->taskKey($task, $shared), $at, self::DEFERRAL_SECONDS);
    }

    /**
     * Claims the one catch-up a task may have on a date. Atomic on stores that support add(), so two nodes
     * sharing a cache cannot both win. False when it was already claimed or the store failed.
     */
    public function claimCatchUp(string $task, string $date, bool $shared = false): bool
    {
        try {
            return $this->cache->add(self::PREFIX . 'catchup-done:' . $this->taskKey($task, $shared) . ':' . $date, 1, 2 * 86400);
        } catch (Throwable) {
            return false;
        }
    }

    /** Gives the claim back when the catch-up did not actually run, so it can be tried again. */
    public function releaseCatchUp(string $task, string $date, bool $shared = false): void
    {
        try {
            $this->cache->forget(self::PREFIX . 'catchup-done:' . $this->taskKey($task, $shared) . ':' . $date);
        } catch (Throwable) {
            // Monitoring must never break the work it observes.
        }
    }

    private function taskKey(string $task, bool $shared = false): string
    {
        return hash('xxh128', ($shared ? '' : $this->node()) . '|' . $task);
    }

    /** @return list<array<string, mixed>> */
    private function rawSamples(string $node): array
    {
        $samples = $this->get('samples:' . $node);

        if (! is_array($samples)) {
            return [];
        }

        return array_values(array_filter($samples, is_array(...)));
    }

    /** @return array<string, mixed> */
    private function rawNodes(): array
    {
        $nodes = $this->get('nodes');

        return is_array($nodes) ? $nodes : [];
    }

    private function get(string $key): mixed
    {
        try {
            return $this->cache->get(self::PREFIX . $key);
        } catch (Throwable) {
            return null;
        }
    }

    private function put(string $key, mixed $value, int $seconds = self::RETENTION_SECONDS): void
    {
        try {
            $this->cache->put(self::PREFIX . $key, $value, $seconds);
        } catch (Throwable) {
            // Monitoring must never break the work it observes.
        }
    }
}
