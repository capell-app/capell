<?php

declare(strict_types=1);

use Capell\Core\Data\Diagnostics\DoctorCheckResultData;
use Capell\Core\Enums\Diagnostics\DoctorCheckSeverity;
use Capell\Core\Support\Diagnostics\Checks\HostingPlatformCheck;
use Capell\Core\Support\Diagnostics\SchedulerHeartbeat;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Config;

beforeEach(function (): void {
    $this->originalEnvironment = app()->environment();
    unset($_SERVER['LARAVEL_CLOUD']);

    Config::set('capell.cloud.install_mode');
    Config::set('capell.release_root_mode', 'mutable');
    Config::set('queue.default', 'database');
    Config::set('queue.connections.database.driver', 'database');
    Config::set('queue.connections.sync.driver', 'sync');
});

afterEach(function (): void {
    unset($_SERVER['LARAVEL_CLOUD']);
    useHostingEnvironment($this->originalEnvironment);
});

function useHostingEnvironment(string $environment): void
{
    app()->detectEnvironment(static fn (): string => $environment);
}

function hostingCheckResult(): DoctorCheckResultData
{
    return resolve(HostingPlatformCheck::class)->check();
}

it('reports Laravel Cloud from the platform marker and names the release root mode', function (): void {
    $_SERVER['LARAVEL_CLOUD'] = '1';
    Config::set('capell.release_root_mode', 'immutable');
    resolve(SchedulerHeartbeat::class)->record();

    $result = hostingCheckResult();

    expect($result->passed)->toBeTrue()
        ->and($result->id)->toBe('core.hosting.platform')
        ->and($result->severity)->toBe(DoctorCheckSeverity::Warning)
        ->and($result->evidence['platform'])->toBe('laravel-cloud')
        ->and($result->evidence['platform_signal'])->toBe('LARAVEL_CLOUD')
        ->and($result->evidence['release_root_mode'])->toBe('immutable')
        ->and($result->message)->toContain('Laravel Cloud')
        ->and($result->message)->toContain('immutable');
});

it('reports Laravel Cloud from the Capell cloud install mode when the platform marker is absent', function (): void {
    Config::set('capell.cloud.install_mode', 'cloud');
    Config::set('capell.release_root_mode', 'immutable');

    $result = hostingCheckResult();

    expect($result->evidence['platform'])->toBe('laravel-cloud')
        ->and($result->evidence['platform_signal'])->toBe('CAPELL_INSTALL_MODE');
});

it('warns when a mutable release root runs on Laravel Cloud', function (): void {
    $_SERVER['LARAVEL_CLOUD'] = '1';
    Config::set('capell.release_root_mode', 'mutable');
    resolve(SchedulerHeartbeat::class)->record();

    $result = hostingCheckResult();

    expect($result->passed)->toBeFalse()
        ->and($result->isCriticalFailure())->toBeFalse()
        ->and($result->evidence['hazards'])->toContain('mutable_release_root')
        ->and($result->message)->toContain('CAPELL_RELEASE_ROOT_MODE')
        ->and($result->remediation)->toContain('CAPELL_RELEASE_ROOT_MODE=immutable')
        ->and($result->remediation)->toContain('https://docs.capell.app/core/operations/deploy-on-laravel-cloud/');
});

it('does not treat a mutable release root as a hazard on a self-managed host', function (): void {
    useHostingEnvironment('production');
    resolve(SchedulerHeartbeat::class)->record();

    $result = hostingCheckResult();

    expect($result->passed)->toBeTrue()
        ->and($result->evidence['platform'])->toBe('self-managed')
        ->and($result->evidence['hazards'])->toBe([]);
});

it('warns when production runs the synchronous queue connection', function (): void {
    useHostingEnvironment('production');
    Config::set('queue.default', 'sync');
    resolve(SchedulerHeartbeat::class)->record();

    $result = hostingCheckResult();

    expect($result->passed)->toBeFalse()
        ->and($result->evidence['hazards'])->toBe(['sync_queue'])
        ->and($result->message)->toContain('QUEUE_CONNECTION');
});

it('allows the synchronous queue connection outside production', function (): void {
    useHostingEnvironment('local');
    Config::set('queue.default', 'sync');

    $result = hostingCheckResult();

    expect($result->passed)->toBeTrue()
        ->and($result->evidence['hazards'])->toBe([]);
});

it('warns in production when the scheduler has never recorded a heartbeat', function (): void {
    useHostingEnvironment('production');

    $result = hostingCheckResult();

    expect($result->passed)->toBeFalse()
        ->and($result->evidence['hazards'])->toBe(['scheduler_heartbeat_missing'])
        ->and($result->evidence['scheduler_heartbeat_at'])->toBeNull()
        ->and($result->message)->toContain('scheduler')
        ->and($result->remediation)->toContain('schedule:run');
});

it('warns in production when the scheduler heartbeat is stale', function (): void {
    useHostingEnvironment('production');
    resolve(SchedulerHeartbeat::class)->record();

    $this->travel(SchedulerHeartbeat::STALE_AFTER_SECONDS + 60)->seconds();

    $result = hostingCheckResult();

    expect($result->passed)->toBeFalse()
        ->and($result->evidence['hazards'])->toBe(['scheduler_heartbeat_stale'])
        ->and($result->evidence['scheduler_heartbeat_at'])->toBeString();
});

it('does not report a missing heartbeat in the install summary, which runs before the scheduler can have started', function (): void {
    useHostingEnvironment('production');

    $result = resolve(HostingPlatformCheck::class)->check(installSummary: true);

    expect($result->passed)->toBeTrue()
        ->and($result->evidence['hazards'])->toBe([]);
});

it('lists every hazard it finds at once', function (): void {
    $_SERVER['LARAVEL_CLOUD'] = '1';
    useHostingEnvironment('production');
    Config::set('queue.default', 'sync');

    $result = hostingCheckResult();

    expect($result->evidence['hazards'])->toBe(['mutable_release_root', 'sync_queue', 'scheduler_heartbeat_missing']);
});

it('records the scheduler heartbeat from the scheduled task Core registers', function (): void {
    $schedule = resolve(Schedule::class);

    $event = collect($schedule->events())
        ->first(fn (Event $event): bool => $event->description === SchedulerHeartbeat::SCHEDULE_NAME);

    expect($event)->toBeInstanceOf(Event::class);

    if ($event instanceof Event) {
        $event->run(app());
    }

    expect(resolve(SchedulerHeartbeat::class)->lastBeatAt())->not->toBeNull();
});
