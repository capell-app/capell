<?php

declare(strict_types=1);

use Capell\Core\Actions\HostMonitor\ApplyDeferrableTasksAction;
use Capell\Core\Actions\HostMonitor\BuildDeferrableTasksStatusAction;
use Capell\Core\Data\Diagnostics\HostSampleData;
use Capell\Core\Support\HostMonitor\HostMonitorStore;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Console\Scheduling\CacheSchedulingMutex;
use Illuminate\Console\Scheduling\Event;
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

function deferralSample(float $load5, string $node = 'node-a'): HostSampleData
{
    return HostSampleData::from([
        'takenAt' => now()->getTimestamp(),
        'node' => $node,
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

function setHostLoad(float $load5, string $node = 'node-a'): void
{
    // Replace the readings and the remembered status, but keep what the scheduler remembers about deferred tasks.
    Cache::forget('capell:host-monitor:samples:' . $node);
    Cache::forget('capell:host-monitor:state:' . $node);
    resolve(HostMonitorStore::class)->pushSample(deferralSample($load5, $node));
    resolve(HostMonitorStore::class)->pushSample(deferralSample($load5, $node));
}

function runScheduler(): array
{
    $GLOBALS['deferrable_runs'] = [];

    // The schedule remembers which server won each minute for the length of one process; a test runs many minutes in one.
    (fn (): array => $this->mutexCache = [])->call(resolve(Schedule::class));
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

function scheduledTask(string $name): Event
{
    return collect(resolve(Schedule::class)->events())->firstOrFail(fn (Event $event): bool => $event->description === $name);
}

it('catches an onOneServer task up once for the whole installation, not once per node', function (): void {
    Config::set('capell.health.host.deferrable_tasks', ['app:nightly']);
    scheduledTask('app:nightly')->onOneServer();
    $this->travelTo(today()->addHours(3));

    foreach (['node-a', 'node-b'] as $node) {
        Config::set('capell.node_id', $node);
        setHostLoad(9.0, $node);
        runScheduler();
    }

    $this->travelTo(today()->addHours(10));
    $ranOn = [];

    foreach (['node-a', 'node-b'] as $node) {
        Config::set('capell.node_id', $node);
        setHostLoad(0.2, $node);

        if (in_array('nightly', runScheduler(), true)) {
            $ranOn[] = $node;
        }
    }

    expect($ranOn)->toBe(['node-a']);
});

it('does not catch up an onOneServer task another node has already claimed for the day', function (): void {
    Config::set('capell.health.host.deferrable_tasks', ['app:nightly']);
    scheduledTask('app:nightly')->onOneServer();
    resolve(HostMonitorStore::class)->markCatchUpPending('app:nightly', now()->getTimestamp(), true);
    resolve(HostMonitorStore::class)->claimCatchUp('app:nightly', now()->format('Y-m-d'), true);
    setHostLoad(0.2);

    expect(runScheduler())->not->toContain('nightly');
});

it('does not catch up an onOneServer task while another server holds the scheduling mutex', function (): void {
    Config::set('capell.health.host.deferrable_tasks', ['app:nightly']);
    $event = scheduledTask('app:nightly')->onOneServer();
    resolve(HostMonitorStore::class)->markCatchUpPending('app:nightly', now()->getTimestamp(), true);
    setHostLoad(0.2);
    resolve(CacheSchedulingMutex::class)->create($event, now());

    expect(runScheduler())->not->toContain('nightly');
});

it('does not catch up a task whose own condition says no', function (): void {
    Config::set('capell.health.host.deferrable_tasks', ['app:nightly']);
    $allowed = true;
    scheduledTask('app:nightly')->when(static function () use (&$allowed): bool {
        return $allowed;
    })->cron('* * * * *');
    setHostLoad(9.0);
    runScheduler();

    expect(BuildDeferrableTasksStatusAction::run()->deferred)->toBe(['app:nightly']);

    $allowed = false;
    $this->travel(1)->minute();
    setHostLoad(0.2);

    expect(runScheduler())->not->toContain('nightly');
});

it('runs the task instead of losing it when the skip cannot be remembered', function (): void {
    Config::set('capell.health.host.deferrable_tasks', ['app:reports']);
    $cache = new class(new ArrayStore) extends Repository
    {
        public function put($key, $value, $ttl = null): bool
        {
            return is_string($key) && str_contains($key, 'catchup-pending') ? throw new RuntimeException('cache down') : parent::put($key, $value, $ttl);
        }
    };
    app()->instance(HostMonitorStore::class, new HostMonitorStore($cache));
    $store = resolve(HostMonitorStore::class);
    $store->pushSample(deferralSample(9.0));
    $store->pushSample(deferralSample(9.0));

    expect(runScheduler())->toContain('reports');
});

it('uses the event time zone for the catch-up time', function (): void {
    Config::set('capell.health.host.deferrable_tasks', ['app:nightly']);
    Config::set('capell.health.host.deferrable_catch_up_at', '12:00');
    scheduledTask('app:nightly')->timezone('Pacific/Auckland');
    // 10:00 UTC is 23:00 in Auckland, already past 12:00 there.
    resolve(HostMonitorStore::class)->markCatchUpPending('app:nightly', now()->getTimestamp());
    setHostLoad(9.0);

    expect(array_count_values(runScheduler())['nightly'] ?? 0)->toBe(1);
});

it('matches a task by its exact description and a command by its artisan name only', function (): void {
    $schedule = new Schedule;
    $schedule->call(static fn (): int => 1)->name('app:reports weekly');
    $schedule->command('capell:tidy --force')->description('Tidy up');

    Config::set('capell.health.host.deferrable_tasks', ['app:reports']);
    expect(ApplyDeferrableTasksAction::run($schedule))->toBe(0);

    Config::set('capell.health.host.deferrable_tasks', ['app:reports weekly', 'capell:tidy']);
    expect(ApplyDeferrableTasksAction::run($schedule))->toBe(2);
});

it('does not run a withoutOverlapping task over a run that is still going and keeps it owed', function (): void {
    Config::set('capell.health.host.deferrable_tasks', ['app:nightly']);
    $event = scheduledTask('app:nightly')->withoutOverlapping();
    $event->mutex->create($event);

    resolve(HostMonitorStore::class)->markCatchUpPending('app:nightly', now()->getTimestamp());
    setHostLoad(0.2);

    expect(runScheduler())->not->toContain('nightly')
        ->and(BuildDeferrableTasksStatusAction::run()->deferred)->toBe(['app:nightly']);

    $event->mutex->forget($event);
    $this->travel(1)->minute();
    setHostLoad(0.2);

    expect(array_count_values(runScheduler())['nightly'] ?? 0)->toBe(1);
});

it('does not catch a one-server task up again when a busy node records a skip after another node ran it', function (): void {
    Config::set('capell.health.host.deferrable_tasks', ['app:nightly']);
    scheduledTask('app:nightly')->onOneServer();
    $this->travelTo(today()->addHours(3));

    // Node A is calm and wins the one-server mutex; node B is critical in the same minute and records a skip.
    Config::set('capell.node_id', 'node-a');
    setHostLoad(0.2, 'node-a');
    $ran = runScheduler();

    Config::set('capell.node_id', 'node-b');
    setHostLoad(9.0, 'node-b');
    $ran = [...$ran, ...runScheduler()];

    $this->travelTo(today()->addHours(10));
    Config::set('capell.node_id', 'node-b');
    setHostLoad(0.2, 'node-b');
    $ran = [...$ran, ...runScheduler()];

    expect(array_count_values($ran)['nightly'] ?? 0)->toBe(1);
});
