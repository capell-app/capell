<?php

declare(strict_types=1);

use Capell\Core\Contracts\Health\HostMetricsSource;
use Capell\Core\Data\Diagnostics\HostSampleData;
use Capell\Core\Support\HostMonitor\HostMonitorStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;

require_once __DIR__ . '/HostMonitorTestSupport.php';

beforeEach(fn () => Cache::flush());

it('reads a plausible sample from the server it runs on', function (): void {
    $sample = resolve(HostMetricsSource::class)->read();

    expect($sample)->toBeInstanceOf(HostSampleData::class)
        ->and($sample->cores)->toBeGreaterThanOrEqual(1)
        ->and($sample->load5)->toBeGreaterThanOrEqual(0.0)
        ->and($sample->node)->toBe(resolve(HostMonitorStore::class)->node())
        ->and($sample->takenAt)->toBeLessThanOrEqual(Date::now()->getTimestamp())
        ->and($sample->topProcesses)->toBeArray();
});

it('survives being stored and read back', function (): void {
    $sample = resolve(HostMetricsSource::class)->read();
    $store = resolve(HostMonitorStore::class);

    $store->pushSample($sample);

    expect($store->samples($sample->node))->toHaveCount(1)
        ->and($store->samples($sample->node)[0]->load5)->toBe($sample->load5);
});

it('reports the busiest processes between two readings on Linux', function (): void {
    if (! is_dir('/proc/self')) {
        $this->markTestSkipped('Per-process CPU readings need /proc.');
    }

    $first = resolve(HostMetricsSource::class)->read();
    $store = resolve(HostMonitorStore::class);
    $snapshot = $store->cpuSnapshot($first->node);

    expect($snapshot)->not->toBeNull()
        ->and($snapshot['ticks'] ?? [])->not->toBe([]);

    // Pretend the previous reading was ten seconds ago with no CPU used, so every process shows the CPU it has used since.
    $store->putCpuSnapshot($first->node, ['at' => microtime(true) - 10, 'ticks' => array_map(static fn (): int => 0, $snapshot['ticks'] ?? [])]);

    $second = resolve(HostMetricsSource::class)->read();

    expect($second->topProcesses)->not->toBe([])
        ->and($second->topProcesses[0])->toHaveKeys(['pid', 'user', 'cpu', 'command']);
});

it('forgets a reading it cannot decode instead of failing', function (): void {
    $store = resolve(HostMonitorStore::class);
    Cache::put('capell:host-monitor:samples:test-node', [['takenAt' => 'not a time'], 'nonsense'], 600);

    expect($store->samples('test-node'))->toBe([]);
});

it('lists a node named only with digits as a string', function (): void {
    $store = resolve(HostMonitorStore::class);
    $store->pushSample(hostSample(['node' => '12345']));

    expect($store->nodes())->toBe(['12345']);
});
