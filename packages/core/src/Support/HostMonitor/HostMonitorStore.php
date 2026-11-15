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
