<?php

declare(strict_types=1);

use Capell\Core\Contracts\Health\HostMetricsSource;
use Capell\Core\Data\Diagnostics\HostSampleData;
use Capell\Core\Support\HostMonitor\HostMonitorStore;

/** @param  array<string, mixed>  $overrides */
function hostSample(array $overrides = []): HostSampleData
{
    return HostSampleData::from([
        'takenAt' => now()->getTimestamp(),
        'node' => 'test-node',
        'cores' => 2,
        'load1' => 0.2,
        'load5' => 0.2,
        'load15' => 0.2,
        'memoryTotalBytes' => 4_000_000_000,
        'memoryAvailableBytes' => 2_000_000_000,
        'swapTotalBytes' => 1_000_000_000,
        'swapUsedBytes' => 0,
        'topProcesses' => [],
        'schedulerCpuSeconds' => 5.0,
        'schedulerWallSeconds' => 8.0,
        'schedulerTasks' => 10,
        'schedulerProcesses' => 4,
        'slowestSchedulerTasks' => [],
        ...$overrides,
    ]);
}

/** A reading that is heavy enough to be critical on a two-core server. */
function busySample(): HostSampleData
{
    return hostSample(['load5' => 4.0, 'topProcesses' => [['pid' => 42, 'user' => 'www', 'cpu' => 180.0, 'command' => 'php artisan queue:work']]]);
}

function recordSamples(HostSampleData $sample, int $times): void
{
    $store = resolve(HostMonitorStore::class);

    for ($i = 0; $i < $times; $i++) {
        $store->pushSample($sample);
    }
}

/** Answers with each queued reading in turn, then keeps repeating the last. */
function fakeHostSource(HostSampleData ...$samples): void
{
    app()->instance(HostMetricsSource::class, new class($samples) implements HostMetricsSource
    {
        /** @param  list<HostSampleData>  $samples */
        public function __construct(private array $samples) {}

        public function read(): HostSampleData
        {
            return count($this->samples) > 1 ? array_shift($this->samples) : $this->samples[0];
        }
    });
}
