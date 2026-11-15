<?php

declare(strict_types=1);

use Capell\Core\Actions\HostMonitor\AssessHostLoadAction;
use Capell\Core\Enums\Diagnostics\HostMonitorStatus;
use Capell\Core\Support\HostMonitor\HostMonitorStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;

require_once __DIR__ . '/HostMonitorTestSupport.php';

beforeEach(function (): void {
    Cache::flush();
    Config::set('capell.health.host.sustained_samples', 3);
});

it('reports healthy when nothing has been recorded', function (): void {
    $assessment = resolve(AssessHostLoadAction::class)->handle('test-node');

    expect($assessment->status)->toBe(HostMonitorStatus::Ok)
        ->and($assessment->sample)->toBeNull()
        ->and($assessment->needsAttention())->toBeFalse();
});

it('stays healthy for readings within every limit', function (): void {
    recordSamples(hostSample(), 3);

    expect(resolve(AssessHostLoadAction::class)->handle('test-node')->status)->toBe(HostMonitorStatus::Ok);
});

it('does not raise a status until enough consecutive readings agree', function (): void {
    recordSamples(busySample(), 2);

    expect(resolve(AssessHostLoadAction::class)->handle('test-node')->status)->toBe(HostMonitorStatus::Ok);
});

it('raises critical once the required readings agree and names the load and cores', function (): void {
    recordSamples(busySample(), 3);

    $assessment = resolve(AssessHostLoadAction::class)->handle('test-node');

    expect($assessment->status)->toBe(HostMonitorStatus::Critical)
        ->and($assessment->reasons[0]['signal'])->toBe('load')
        ->and($assessment->reasons[0]['message'])->toContain('4.00')->toContain('2 CPU cores');
});

it('raises only a warning when the worst reading in the window is a warning', function (): void {
    recordSamples(hostSample(['load5' => 4.0]), 1);
    recordSamples(hostSample(['load5' => 1.8]), 2);

    expect(resolve(AssessHostLoadAction::class)->handle('test-node')->status)->toBe(HostMonitorStatus::Warning);
});

it('holds a raised status while the readings are mixed, then clears it when they agree it has gone', function (): void {
    $store = resolve(HostMonitorStore::class);
    $store->putState('test-node', ['status' => 'critical', 'since' => now()->getTimestamp() - 600, 'notified_at' => null]);

    recordSamples(busySample(), 2);
    recordSamples(hostSample(), 1);

    expect(resolve(AssessHostLoadAction::class)->handle('test-node')->status)->toBe(HostMonitorStatus::Critical);

    recordSamples(hostSample(), 2);

    expect(resolve(AssessHostLoadAction::class)->handle('test-node')->status)->toBe(HostMonitorStatus::Ok);
});

it('grades memory, swap and the scheduler from configurable limits', function (string $signal, array $overrides): void {
    recordSamples(hostSample($overrides), 3);

    $assessment = resolve(AssessHostLoadAction::class)->handle('test-node');

    expect($assessment->status)->not->toBe(HostMonitorStatus::Ok)
        ->and(array_column($assessment->reasons, 'signal'))->toContain($signal);
})->with([
    'low memory' => ['memory', ['memoryAvailableBytes' => 200_000_000]],
    'heavy swap' => ['swap', ['swapUsedBytes' => 900_000_000]],
    'scheduler cpu' => ['scheduler_cpu', ['schedulerCpuSeconds' => 50.0]],
    'scheduler overlap' => ['scheduler_wall', ['schedulerWallSeconds' => 70.0]],
]);

it('treats readings a platform cannot supply as no problem', function (): void {
    recordSamples(hostSample([
        'memoryTotalBytes' => null,
        'memoryAvailableBytes' => null,
        'swapTotalBytes' => null,
        'swapUsedBytes' => null,
        'schedulerCpuSeconds' => null,
        'schedulerWallSeconds' => null,
    ]), 3);

    expect(resolve(AssessHostLoadAction::class)->handle('test-node')->status)->toBe(HostMonitorStatus::Ok);
});

it('uses thresholds from configuration', function (): void {
    Config::set('capell.health.host.load.warning_ratio', 0.05);
    Config::set('capell.health.host.load.critical_ratio', 9.0);
    recordSamples(hostSample(['load5' => 0.5]), 3);

    expect(resolve(AssessHostLoadAction::class)->handle('test-node')->status)->toBe(HostMonitorStatus::Warning);
});
