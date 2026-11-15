<?php

declare(strict_types=1);

use Capell\Admin\Filament\Widgets\Dashboard\HostLoadAttentionFilamentWidget;
use Capell\Admin\Support\Diagnostics\HostLoadSiteHealthReportExtender;
use Capell\Core\Data\Diagnostics\HostAssessmentData;
use Capell\Core\Data\Diagnostics\HostSampleData;
use Capell\Core\Support\HostMonitor\HostMonitorStore;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(CreatesAdminUser::class);

beforeEach(function (): void {
    Cache::flush();
    Config::set('capell.health.host.enabled', true);
    Config::set('capell.health.host.sustained_samples', 2);
});

function hostWidgetUser(bool $canViewSiteHealth = true): void
{
    Permission::findOrCreate('View:SiteHealthPage', 'web');
    test()->actingAsUser();

    if ($canViewSiteHealth) {
        test()->authenticatedUser()->givePermissionTo('View:SiteHealthPage');
    }
}

/** @param  array<string, mixed>  $overrides */
function recordHostReadings(string $node, int $times, array $overrides = []): void
{
    $store = resolve(HostMonitorStore::class);

    for ($i = 0; $i < $times; $i++) {
        $store->pushSample(HostSampleData::from([
            'takenAt' => now()->getTimestamp(),
            'node' => $node,
            'cores' => 2,
            'load1' => 0.1,
            'load5' => 0.1,
            'load15' => 0.1,
            'topProcesses' => [],
            'slowestSchedulerTasks' => [],
            ...$overrides,
        ]));
    }
}

it('stays hidden while every server is healthy', function (): void {
    hostWidgetUser();
    recordHostReadings('web1', 3);

    expect(HostLoadAttentionFilamentWidget::canView())->toBeFalse();
});

it('shows the load, the cause and a link to Site Health once load is sustained', function (): void {
    hostWidgetUser();
    recordHostReadings('web1', 2, ['load5' => 4.0, 'topProcesses' => [['pid' => 7, 'user' => 'www', 'cpu' => 190.0, 'command' => 'php artisan queue:work']]]);

    expect(HostLoadAttentionFilamentWidget::canView())->toBeTrue();

    Livewire::test(HostLoadAttentionFilamentWidget::class)
        ->assertSee('web1')
        ->assertSee('4.00')
        ->assertSee('php artisan queue:work')
        ->assertSee(__('capell-admin::host-monitor.open_site_health'));
});

it('lists the worst server first and omits healthy ones', function (): void {
    hostWidgetUser();
    recordHostReadings('quiet', 2);
    recordHostReadings('warm', 2, ['load5' => 1.8]);
    recordHostReadings('hot', 2, ['load5' => 5.0]);

    $nodes = array_map(static fn (HostAssessmentData $assessment): string => (string) $assessment->sample?->node, HostLoadAttentionFilamentWidget::assessments());

    expect($nodes)->toBe(['hot', 'warm']);
});

it('is hidden from people who cannot open Site Health', function (): void {
    hostWidgetUser(canViewSiteHealth: false);
    recordHostReadings('web1', 2, ['load5' => 4.0]);

    expect(HostLoadAttentionFilamentWidget::canView())->toBeFalse();
});

it('is hidden when monitoring is turned off', function (): void {
    hostWidgetUser();
    recordHostReadings('web1', 2, ['load5' => 4.0]);
    Config::set('capell.health.host.enabled', false);

    expect(HostLoadAttentionFilamentWidget::canView())->toBeFalse();
});

it('adds a server load section to Site Health with a row for each server', function (): void {
    recordHostReadings('web1', 2);
    recordHostReadings('web2', 2, ['load5' => 4.0, 'schedulerTasks' => 12, 'schedulerProcesses' => 9, 'slowestSchedulerTasks' => [['command' => 'capell:search-doctor', 'seconds' => 3.5]]]);

    $sections = resolve(HostLoadSiteHealthReportExtender::class)->sections();
    $rows = collect($sections[0]->checks)->keyBy('label');

    expect($sections)->toHaveCount(1)
        ->and($rows['web1']?->status)->toBe('green')
        ->and($rows['web2']?->status)->toBe('red')
        ->and($rows['web2']?->detail)->toContain('9 PHP processes')->toContain('capell:search-doctor (3.5s)')
        ->and($rows['web2']?->remediation)->not->toBeNull()
        ->and($rows['web1']?->remediation)->toBeNull();
});

it('explains that nothing has been recorded yet instead of showing an empty section', function (): void {
    $checks = resolve(HostLoadSiteHealthReportExtender::class)->sections()[0]->checks;

    $check = $checks[0] ?? null;

    expect($checks)->toHaveCount(1)
        ->and($check?->status)->toBe('amber')
        ->and($check?->detail)->toBe(__('capell-admin::host-monitor.section_empty'));
});

it('adds no Site Health section while monitoring is turned off', function (): void {
    Config::set('capell.health.host.enabled', false);

    expect(resolve(HostLoadSiteHealthReportExtender::class)->sections())->toBe([]);
});
