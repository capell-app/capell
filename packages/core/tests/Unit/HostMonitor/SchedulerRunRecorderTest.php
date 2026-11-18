<?php

declare(strict_types=1);

use Capell\Core\Support\HostMonitor\CommandNameDescriber;
use Capell\Core\Support\HostMonitor\HostMonitorStore;
use Capell\Core\Support\HostMonitor\SchedulerRunRecorder;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    // A run is filed under the minute it started and read back from the previous
    // minute. Pin the clock to the start of a minute: on the real clock a minute
    // can roll over between starting() and travel(61), and the run is not found.
    $this->travelTo(now()->startOfMinute());
    Cache::flush();
    resolve(SchedulerRunRecorder::class)->reset();
});

it('counts the processes a scheduler run starts and ignores in-process tasks', function (): void {
    $schedule = new Schedule;
    $command = $schedule->command('capell:example --secret=hunter2');
    $callback = $schedule->call(static fn (): int => 1)->name('capell:in-process');
    $recorder = resolve(SchedulerRunRecorder::class);

    $recorder->starting();
    $recorder->finished(new ScheduledTaskFinished($command, 1.25));
    $recorder->starting();
    $recorder->finished(new ScheduledTaskFinished($callback, 0.01));

    $this->travel(61)->seconds();
    $run = $recorder->lastCompletedRun(resolve(HostMonitorStore::class)->node());

    expect($run)->not->toBeNull()
        ->and($run['tasks'] ?? null)->toBe(2)
        ->and($run['processes'] ?? null)->toBe(1)
        ->and($run['wall_seconds'] ?? -1)->toBeGreaterThanOrEqual(0.0)
        ->and($run['slowest'][0]['command'] ?? null)->toBe('capell:example')
        ->and($run['slowest'][0]['seconds'] ?? null)->toBe(1.25)
        ->and(json_encode($run))->not->toContain('hunter2');
});

it('counts a failed task as part of the run', function (): void {
    $command = (new Schedule)->command('capell:failing');
    $recorder = resolve(SchedulerRunRecorder::class);

    $recorder->starting();
    $recorder->failed(new ScheduledTaskFailed($command, new RuntimeException('boom')));

    $this->travel(61)->seconds();

    expect($recorder->lastCompletedRun(resolve(HostMonitorStore::class)->node())['tasks'] ?? null)->toBe(1);
});

it('does not report a run that is still in progress as complete', function (): void {
    $command = (new Schedule)->command('capell:example');
    $recorder = resolve(SchedulerRunRecorder::class);

    $recorder->starting();
    $recorder->finished(new ScheduledTaskFinished($command, 0.5));

    expect($recorder->lastCompletedRun(resolve(HostMonitorStore::class)->node()))->toBeNull();
});

it('records nothing for a task that finished without a run starting', function (): void {
    $command = (new Schedule)->command('capell:example');

    resolve(SchedulerRunRecorder::class)->finished(new ScheduledTaskFinished($command, 0.5));

    $this->travel(61)->seconds();

    expect(resolve(SchedulerRunRecorder::class)->lastCompletedRun(resolve(HostMonitorStore::class)->node()))->toBeNull();
});

it('names a process without its arguments', function (array $arguments, string $expected): void {
    expect(CommandNameDescriber::describe($arguments))->toBe($expected);
})->with([
    'artisan command' => [['/usr/bin/php8.4', 'artisan', 'queue:work', '--queue=default'], 'php8.4 artisan queue:work'],
    'relative artisan' => [['php', '/var/www/app/artisan', 'schedule:run'], 'php artisan schedule:run'],
    'other program drops arguments' => [['/usr/sbin/mariadbd', '--password=secret'], 'mariadbd'],
    'artisan with an unsafe argument' => [['php', 'artisan', '--token=abc'], 'php'],
    'program that rewrites its process title' => [['redis-server 127.0.0.1:6379 --requirepass hunter2'], 'redis-server'],
    'empty' => [[], ''],
]);

it('records nothing while monitoring is turned off', function (): void {
    config()->set('capell.health.host.enabled', false);
    $command = (new Schedule)->command('capell:example');
    $recorder = resolve(SchedulerRunRecorder::class);

    $recorder->starting();
    $recorder->finished(new ScheduledTaskFinished($command, 0.5));
    $this->travel(61)->seconds();

    expect($recorder->lastCompletedRun(resolve(HostMonitorStore::class)->node()))->toBeNull();
});
