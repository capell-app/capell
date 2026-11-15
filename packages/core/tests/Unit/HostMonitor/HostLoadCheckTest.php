<?php

declare(strict_types=1);

use Capell\Core\Actions\Diagnostics\BuildDoctorReportAction;
use Capell\Core\Enums\Diagnostics\DoctorCheckSeverity;
use Capell\Core\Support\Diagnostics\Checks\HostLoadCheck;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;

require_once __DIR__ . '/HostMonitorTestSupport.php';

beforeEach(function (): void {
    Cache::flush();
    Config::set('capell.node_id', 'test-node');
    Config::set('capell.health.host.enabled', true);
    Config::set('capell.health.host.sustained_samples', 3);
});

it('passes and says there is nothing to report before any reading exists', function (): void {
    $result = resolve(HostLoadCheck::class)->check();

    expect($result->passed)->toBeTrue()
        ->and($result->id)->toBe('core.host.load')
        ->and($result->message)->toContain('No server readings');
});

it('passes while monitoring is turned off', function (): void {
    Config::set('capell.health.host.enabled', false);

    expect(resolve(HostLoadCheck::class)->check()->message)->toContain('turned off');
});

it('passes with the load and core count when the server is healthy', function (): void {
    recordSamples(hostSample(), 3);

    $result = resolve(HostLoadCheck::class)->check();

    expect($result->passed)->toBeTrue()
        ->and($result->message)->toContain('0.20')->toContain('2 cores');
});

it('warns without blocking when load is sustained, and says what to do', function (): void {
    recordSamples(busySample(), 3);

    $result = resolve(HostLoadCheck::class)->check();

    expect($result->passed)->toBeFalse()
        ->and($result->severity)->toBe(DoctorCheckSeverity::Warning)
        ->and($result->isCriticalFailure())->toBeFalse()
        ->and($result->message)->toContain('4.00')
        ->and($result->remediation)->toContain('Site Health')
        ->and($result->evidence['status'])->toBe('critical')
        ->and($result->evidence['signals'])->toContain('load');
});

it('is part of the doctor report', function (): void {
    expect(BuildDoctorReportAction::CORE_CHECKS)->toContain(HostLoadCheck::class);
});

it('samples every minute inside the scheduler process rather than starting another one', function (): void {
    $event = collect(resolve(Schedule::class)->events())
        ->first(static fn (CallbackEvent|Event $event): bool => $event->description === 'capell:host-monitor-sample');

    expect($event)->toBeInstanceOf(CallbackEvent::class)
        ->and($event?->expression)->toBe('* * * * *');
});
