<?php

declare(strict_types=1);

use Capell\Core\Actions\HostMonitor\BuildDeferrableTasksStatusAction;
use Capell\Core\Data\Diagnostics\HostSampleData;
use Capell\Core\Support\HostMonitor\HostMonitorStore;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;

beforeEach(function (): void {
    // The production console kernel does this in handle(); a test that calls schedule:run needs it too.
    resolve(Kernel::class)->rerouteSymfonyCommandEvents();
    Cache::flush();
    Config::set('capell.node_id', 'node-a');
    Config::set('capell.health.host.enabled', true);
    Config::set('capell.health.host.sustained_samples', 2);
    Config::set('capell.health.host.deferrable_catch_up_at', '23:59');
    $this->travelTo(today()->addHours(10));
    $GLOBALS['deferrable_runs'] = [];

    // The schedule is a singleton: start each test from an empty one.
    $schedule = resolve(Schedule::class);
    (fn (): array => $this->events = [])->call($schedule);

    $schedule->call(static function (): void {
        $GLOBALS['deferrable_runs'][] = 'reports';
    })->name('app:reports')->everyMinute();
    $schedule->call(static function (): void {
        $GLOBALS['deferrable_runs'][] = 'cleanup';
    })->name('app:cleanup')->everyMinute();
    $schedule->call(static function (): void {
        $GLOBALS['deferrable_runs'][] = 'nightly';
    })->name('app:nightly')->dailyAt('03:00');
});

afterEach(function (): void {
    unset($GLOBALS['deferrable_runs']);
});

function deferralSample(float $load5): HostSampleData
{
    return HostSampleData::from([
        'takenAt' => now()->getTimestamp(),
        'node' => 'node-a',
        'cores' => 2,
        'load1' => $load5,
        'load5' => $load5,
        'load15' => $load5,
        'memoryTotalBytes' => 1000,
        'memoryAvailableBytes' => 500,
        'swapTotalBytes' => 1000,
        'swapUsedBytes' => 100,
        'queueDepth' => 0,
        'slowestSchedulerTasks' => [],
    ]);
}

function setHostLoad(float $load5): void
{
    // Replace the readings and the remembered status, but keep what the scheduler remembers about deferred tasks.
    Cache::forget('capell:host-monitor:samples:node-a');
    Cache::forget('capell:host-monitor:state:node-a');
    resolve(HostMonitorStore::class)->pushSample(deferralSample($load5));
    resolve(HostMonitorStore::class)->pushSample(deferralSample($load5));
}

function runScheduler(): array
{
    $GLOBALS['deferrable_runs'] = [];
    Artisan::call('schedule:run');

    return $GLOBALS['deferrable_runs'];
}

it('changes nothing while the list is empty, even under critical load', function (): void {
    setHostLoad(9.0);

    expect(runScheduler())->toContain('reports', 'cleanup')
        ->and(BuildDeferrableTasksStatusAction::run()->enabled())->toBeFalse();
});

it('skips a listed task while the server is critically busy and leaves unlisted tasks alone', function (): void {
    Config::set('capell.health.host.deferrable_tasks', ['app:reports']);
    setHostLoad(9.0);

    $ran = runScheduler();

    expect($ran)->toContain('cleanup')
        ->and($ran)->not->toContain('reports')
        ->and(BuildDeferrableTasksStatusAction::run()->deferred)->toBe(['app:reports']);
});

it('runs a listed task again as soon as the server recovers and does not run it twice', function (): void {
    Config::set('capell.health.host.deferrable_tasks', ['app:reports']);
    setHostLoad(9.0);
    runScheduler();

    $this->travel(1)->minute();
    setHostLoad(0.2);
    $ran = runScheduler();

    expect(array_count_values($ran)['reports'] ?? 0)->toBe(1)
        ->and(BuildDeferrableTasksStatusAction::run()->deferred)->toBe([]);
});

it('catches up a skipped daily task once when the server recovers, then at most once a day', function (): void {
    Config::set('capell.health.host.deferrable_tasks', ['app:nightly']);
    $this->travelTo(today()->addHours(3));
    setHostLoad(9.0);

    expect(runScheduler())->not->toContain('nightly')
        ->and(BuildDeferrableTasksStatusAction::run()->deferred)->toBe(['app:nightly']);

    $this->travelTo(today()->addHours(10));
    setHostLoad(0.2);

    // Not due until tomorrow, yet it has been owed since 03:00.
    expect(array_count_values(runScheduler())['nightly'] ?? 0)->toBe(1);

    $this->travel(1)->minute();

    // Deferred again later the same day: no second catch-up before tomorrow.
    resolve(HostMonitorStore::class)->markCatchUpPending('app:nightly', now()->getTimestamp());

    expect(runScheduler())->not->toContain('nightly')
        ->and(BuildDeferrableTasksStatusAction::run()->deferred)->toBe(['app:nightly']);

    $this->travelTo(now()->addDay()->startOfDay()->addHours(10));
    setHostLoad(0.2);

    expect(array_count_values(runScheduler())['nightly'] ?? 0)->toBe(1);
});

it('catches up at the daily catch-up time even if the server is still busy', function (): void {
    Config::set('capell.health.host.deferrable_tasks', ['app:nightly']);
    Config::set('capell.health.host.deferrable_catch_up_at', '12:00');
    $this->travelTo(today()->addHours(3));
    setHostLoad(9.0);
    runScheduler();

    $this->travelTo(today()->addHours(11));
    setHostLoad(9.0);

    expect(runScheduler())->not->toContain('nightly');

    $this->travelTo(today()->addHours(12));
    setHostLoad(9.0);

    expect(array_count_values(runScheduler())['nightly'] ?? 0)->toBe(1);

    $this->travel(1)->minute();
    setHostLoad(9.0);

    expect(runScheduler())->not->toContain('nightly');
});
