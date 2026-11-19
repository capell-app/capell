<?php

declare(strict_types=1);

use Capell\Core\Actions\HostMonitor\HostLoadTrendAction;
use Capell\Core\Actions\HostMonitor\PruneHostLoadRollupsAction;
use Capell\Core\Actions\HostMonitor\RollUpHostLoadAction;
use Capell\Core\Contracts\Health\HostBusyGate;
use Capell\Core\Data\Diagnostics\HostSampleData;
use Capell\Core\Models\HostLoadRollup;
use Capell\Core\Support\HostMonitor\DeferWhenHostBusy;
use Capell\Core\Support\HostMonitor\HostLoadRetention;
use Capell\Core\Support\HostMonitor\HostMonitorStore;
use Capell\Core\Support\HostMonitor\LocalHostMetricsSource;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Cache::flush();
    Config::set('capell.node_id', 'node-a');
    Config::set('capell.health.host.enabled', true);
    Config::set('capell.health.host.history.enabled', true);
    Config::set('capell.health.host.history_samples', 500);
    Config::set('capell.health.host.sustained_samples', 2);
    $this->travelTo(now()->startOfHour()->addMinutes(30));
});

/** @param  array<string, mixed>  $overrides */
function loadSample(int $takenAt, array $overrides = []): HostSampleData
{
    return HostSampleData::from([
        'takenAt' => $takenAt,
        'node' => 'node-a',
        'cores' => 2,
        'load1' => 0.5,
        'load5' => 0.5,
        'load15' => 0.5,
        'memoryTotalBytes' => 1000,
        'memoryAvailableBytes' => 500,
        'swapTotalBytes' => 1000,
        'swapUsedBytes' => 100,
        'queueDepth' => 3,
        'slowestSchedulerTasks' => [],
        ...$overrides,
    ]);
}

function pushLoad(HostSampleData ...$samples): void
{
    foreach ($samples as $sample) {
        resolve(HostMonitorStore::class)->pushSample($sample);
    }
}

it('rolls readings into one row per hour and never counts a reading twice', function (): void {
    $hour = now()->startOfHour()->getTimestamp();
    pushLoad(
        loadSample($hour - 600, ['load5' => 1.0]),
        loadSample($hour + 60, ['load5' => 3.0, 'memoryAvailableBytes' => 100, 'queueDepth' => 40, 'slowestSchedulerTasks' => [['command' => 'capell:slow', 'seconds' => 12.5]]]),
        loadSample($hour + 120, ['load5' => 1.0]),
    );

    expect(resolve(RollUpHostLoadAction::class)->handle())->toBe(3);

    $rows = HostLoadRollup::query()->orderBy('hour_start')->get();
    expect($rows)->toHaveCount(2);

    expect($rows->last()?->only(['hour_start', 'samples', 'load5_peak', 'load5_sum', 'memory_available_min_percent', 'queue_depth_peak', 'busiest_tasks']))->toBe([
        'hour_start' => $hour,
        'samples' => 2,
        'load5_peak' => 3.0,
        'load5_sum' => 4.0,
        'memory_available_min_percent' => 10.0,
        'queue_depth_peak' => 40,
        'busiest_tasks' => [['command' => 'capell:slow', 'seconds' => 12.5]],
    ]);

    // The same readings are still in the cache; a second run must add nothing.
    expect(resolve(RollUpHostLoadAction::class)->handle())->toBe(0)
        ->and(HostLoadRollup::query()->where('hour_start', $hour)->value('samples'))->toBe(2);

    pushLoad(loadSample($hour + 180, ['load5' => 5.0]));
    resolve(RollUpHostLoadAction::class)->handle();

    expect(HostLoadRollup::query()->where('hour_start', $hour)->first()?->only(['samples', 'load5_peak']))->toBe(['samples' => 3, 'load5_peak' => 5.0]);
});

it("keeps one server's history apart from another's", function (): void {
    pushLoad(loadSample(now()->getTimestamp()));
    resolve(HostMonitorStore::class)->pushSample(loadSample(now()->getTimestamp(), ['node' => 'node-b']));

    resolve(RollUpHostLoadAction::class)->handle();

    expect(HostLoadRollup::query()->pluck('node')->all())->toBe(['node-a']);
});

it('records nothing when history is turned off', function (): void {
    Config::set('capell.health.host.history.enabled', false);
    pushLoad(loadSample(now()->getTimestamp()));

    expect(resolve(RollUpHostLoadAction::class)->handle())->toBe(0)
        ->and(HostLoadRollup::query()->count())->toBe(0);
});

it('prunes rollups older than the retention setting and keeps newer ones', function (): void {
    Config::set('capell.health.host.history.retention_days', 10);
    $now = now()->getTimestamp();

    foreach ([$now - 11 * 86400, $now - 9 * 86400] as $hourStart) {
        HostLoadRollup::query()->create(['node' => 'node-a', 'hour_start' => $hourStart, 'samples' => 1]);
    }

    expect(resolve(PruneHostLoadRollupsAction::class)->handle())->toBe(1)
        ->and(HostLoadRollup::query()->count())->toBe(1);
});

it('deletes several batches in a run but stops at its bound', function (): void {
    $old = now()->getTimestamp() - 200 * 86400;
    $total = PruneHostLoadRollupsAction::BATCH * PruneHostLoadRollupsAction::MAXIMUM_BATCHES + 5;

    foreach (array_chunk(range(0, $total - 1), 1000) as $chunk) {
        DB::table((new HostLoadRollup)->getTable())->insert(array_map(
            static fn (int $i): array => ['node' => 'node-a', 'hour_start' => $old - $i * 3600, 'samples' => 1, 'created_at' => now(), 'updated_at' => now()],
            $chunk,
        ));
    }

    expect(resolve(PruneHostLoadRollupsAction::class)->handle())->toBe(PruneHostLoadRollupsAction::BATCH * PruneHostLoadRollupsAction::MAXIMUM_BATCHES)
        ->and(HostLoadRollup::query()->count())->toBe(5);
});

it('clamps a mistyped retention instead of deleting everything or keeping everything', function (int $configured, int $expected): void {
    Config::set('capell.health.host.history.retention_days', $configured);

    expect(HostLoadRetention::days())->toBe($expected);
})->with([[0, 1], [-5, 1], [99999, 365], [30, 30]]);

it('summarises a week into the peak, the busiest hours and the lowest memory', function (): void {
    $base = today()->subDay()->getTimestamp();

    foreach ([[2, 1.0], [3, 1.0], [14, 0.2], [15, 0.2]] as [$hour, $load]) {
        HostLoadRollup::query()->create([
            'node' => 'node-a', 'hour_start' => $base + $hour * 3600, 'cores' => 2, 'samples' => 60,
            'load5_sum' => $load * 60, 'load5_peak' => $load * 1.5,
            'memory_available_min_percent' => $hour === 3 ? 6.0 : 40.0, 'queue_depth_peak' => $hour === 3 ? 900 : 10,
        ]);
    }

    $trend = resolve(HostLoadTrendAction::class)->handle('node-a', 7);

    expect([$trend?->peakRatio, $trend?->busiestWindow['from'] ?? null, $trend?->busiestWindow['to'] ?? null, $trend?->lowestMemoryAvailablePercent, $trend?->peakQueueDepth])->toBe([0.75, 2, 4, 6.0, 900])
        ->and(resolve(HostLoadTrendAction::class)->handle('node-b', 7))->toBeNull();
});

function criticalHost(): void
{
    pushLoad(loadSample(now()->getTimestamp(), ['load5' => 9.0]), loadSample(now()->getTimestamp(), ['load5' => 9.0]), loadSample(now()->getTimestamp(), ['load5' => 9.0]));
}

it('runs a deferrable task when deferring is off even though the server is critical', function (): void {
    criticalHost();

    expect(resolve(HostBusyGate::class)->isBusy())->toBeFalse()
        ->and(resolve(HostBusyGate::class)->shouldDefer('reports'))->toBeFalse();
});

it('runs a deferrable task when there is no history', function (): void {
    Config::set('capell.health.host.defer.enabled', true);

    expect(resolve(HostBusyGate::class)->shouldDefer('reports'))->toBeFalse();
});

it('defers while critical, releases once after the limit and runs again on recovery', function (): void {
    Config::set('capell.health.host.defer.enabled', true);
    Config::set('capell.health.host.defer.max_minutes', 60);
    criticalHost();
    $gate = resolve(HostBusyGate::class);

    expect($gate->shouldDefer('reports'))->toBeTrue()
        ->and($gate->shouldDefer('reports'))->toBeTrue();

    $this->travel(61)->minutes();
    criticalHost();

    // Starvation guard: the busy server cannot hold the task back forever.
    expect($gate->shouldDefer('reports'))->toBeFalse()
        ->and($gate->shouldDefer('reports'))->toBeTrue();

    Cache::flush();
    pushLoad(loadSample(now()->getTimestamp()), loadSample(now()->getTimestamp()));

    expect($gate->shouldDefer('reports'))->toBeFalse();
});

it('releases a task that only runs daily even though the server is busy at every run', function (): void {
    Config::set('capell.health.host.defer.enabled', true);
    criticalHost();
    $gate = resolve(HostBusyGate::class);

    expect($gate->shouldDefer('daily'))->toBeTrue();

    $this->travel(1)->days();
    criticalHost();

    expect($gate->shouldDefer('daily'))->toBeFalse();
});

it("does not let a calm server clear another server's deferral", function (): void {
    Config::set('capell.health.host.defer.enabled', true);
    criticalHost();
    expect(resolve(HostBusyGate::class)->shouldDefer('reports'))->toBeTrue();
    $since = resolve(HostMonitorStore::class)->deferredSince('reports');

    Config::set('capell.node_id', 'node-b');
    expect(resolve(HostBusyGate::class)->shouldDefer('reports'))->toBeFalse();

    Config::set('capell.node_id', 'node-a');
    expect(resolve(HostMonitorStore::class)->deferredSince('reports'))->toBe($since);
});

it('reads a deferral time that a shared cache returns as a string', function (): void {
    Cache::put('capell:host-monitor:deferred:' . hash('xxh128', 'node-a|reports'), '1700000000', 600);

    expect(resolve(HostMonitorStore::class)->deferredSince('reports'))->toBe(1_700_000_000);
});

it('skips a scheduled task through the real scheduler while the server is critical', function (): void {
    Config::set('capell.health.host.defer.enabled', true);
    $ran = 0;
    $event = DeferWhenHostBusy::apply(resolve(Schedule::class)->call(function () use (&$ran): void {
        $ran++;
    })->everyMinute(), 'reports');

    expect($event->filtersPass(app()))->toBeTrue();

    criticalHost();

    expect($event->filtersPass(app()))->toBeFalse();
});

it('reads the queue depth in the sample, and nothing when the queue runs inline', function (): void {
    Queue::fake();
    Queue::push(new class
    {
        public function handle(): void {}
    });

    Config::set('queue.default', 'sync');
    expect(resolve(LocalHostMetricsSource::class)->read()->queueDepth)->toBeNull();

    Config::set('queue.default', 'redis');
    expect(resolve(LocalHostMetricsSource::class)->read()->queueDepth)->toBe(1);
});
