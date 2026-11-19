<?php

declare(strict_types=1);

use Capell\Admin\Filament\Pages\SiteHealthPage;
use Capell\Core\Contracts\Health\ServerSettingsReader;
use Capell\Core\Data\Diagnostics\ServerSettingsData;
use Capell\Core\Models\Site;
use Capell\Core\Support\HostMonitor\HostMonitorStore;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(CreatesAdminUser::class)->group('diagnostics');

/** @param  array<string, mixed>  $overrides */
function fixtureSettingsHost(array $overrides): void
{
    $mib = 1024 ** 2;

    $data = new ServerSettingsData(...[
        'cores' => 8,
        'memoryTotalBytes' => 8 * 1024 * $mib,
        'swapPeakUsedPercent' => 0.0,
        'production' => true,
        'opcacheEnabled' => true,
        'validateTimestamps' => false,
        'opcacheMemoryBytes' => 256 * $mib,
        'opcacheMemoryUsedBytes' => 100 * $mib,
        'internedBufferBytes' => 16 * $mib,
        'internedUsedBytes' => 2 * $mib,
        'maxAcceleratedFiles' => 16229,
        'cachedScripts' => 4000,
        'fpmMaxChildren' => 12,
        'fpmWorkerRssBytes' => 135 * $mib,
        'queueWorkers' => 1,
        'queueWorkerRssBytes' => 135 * $mib,
        'queuePeakDepth' => 10,
        'queueHistoryHours' => 168,
        ...$overrides,
    ]);

    app()->instance(ServerSettingsReader::class, new readonly class($data) implements ServerSettingsReader
    {
        public function __construct(private ServerSettingsData $data) {}

        public function read(): ServerSettingsData
        {
            return $this->data;
        }
    });
}

function openSiteHealthAsAdmin(): void
{
    Permission::findOrCreate('View:SiteHealthPage', 'web');
    test()->actingAsAdmin();
    test()->authenticatedUser()->givePermissionTo('View:SiteHealthPage');
    Site::factory()->createOne();
}

it('shows each recommendation with the setting, the measured value and the change', function (): void {
    openSiteHealthAsAdmin();
    fixtureSettingsHost(['validateTimestamps' => true, 'fpmMaxChildren' => 60, 'queueWorkers' => 3, 'queueWorkerRssBytes' => 3 * 135 * 1024 ** 2, 'queuePeakDepth' => 0]);

    Livewire::test(SiteHealthPage::class)
        ->assertSuccessful()
        ->assertSee(__('capell-admin::server-settings.section'))
        ->assertSee('opcache.validate_timestamps')
        ->assertSee(__('capell-admin::server-settings.change_validate_timestamps', ['recommended' => '0']))
        ->assertSee('pm.max_children')
        ->assertSee(__('capell-admin::server-settings.change_fpm_max_children', ['measured' => '60', 'recommended' => '33']))
        ->assertSee(__('capell-admin::server-settings.change_idle_queue_workers', ['measured' => '3 workers', 'recommended' => '1']));
});

it('shows no recommendations for a healthy host', function (): void {
    openSiteHealthAsAdmin();
    fixtureSettingsHost([]);

    Livewire::test(SiteHealthPage::class)
        ->assertSee(__('capell-admin::server-settings.section'))
        ->assertSee(__('capell-admin::server-settings.healthy'))
        ->assertDontSee('opcache.validate_timestamps');
});

it('says not measured when PHP-FPM cannot be read', function (): void {
    openSiteHealthAsAdmin();
    fixtureSettingsHost(['fpmMaxChildren' => null, 'fpmWorkerRssBytes' => null]);

    Livewire::test(SiteHealthPage::class)
        ->assertSee(__('capell-admin::server-settings.not_measured', ['setting' => 'pm.max_children']));
});

it('does not claim the settings fit when nothing could be measured', function (): void {
    openSiteHealthAsAdmin();
    fixtureSettingsHost([
        'opcacheEnabled' => false,
        'fpmMaxChildren' => null,
        'fpmWorkerRssBytes' => null,
        'swapPeakUsedPercent' => null,
        'queueWorkers' => null,
        'queuePeakDepth' => null,
        'queueHistoryHours' => null,
    ]);

    Livewire::test(SiteHealthPage::class)
        ->assertSee(__('capell-admin::server-settings.not_measured', ['setting' => 'opcache']))
        ->assertSee(__('capell-admin::server-settings.not_measured', ['setting' => 'swap']))
        ->assertDontSee(__('capell-admin::server-settings.healthy'));
});

it('still renders Site Health when the settings reader fails', function (): void {
    openSiteHealthAsAdmin();
    app()->instance(ServerSettingsReader::class, new class implements ServerSettingsReader
    {
        public function read(): ServerSettingsData
        {
            throw new RuntimeException('cannot read');
        }
    });

    Livewire::test(SiteHealthPage::class)
        ->assertSuccessful()
        ->assertDontSee(__('capell-admin::server-settings.section'));
});

it('says the deferring of scheduled tasks is off by default', function (): void {
    openSiteHealthAsAdmin();
    fixtureSettingsHost([]);

    Livewire::test(SiteHealthPage::class)
        ->assertSee(__('capell-admin::server-settings.deferrable_label'))
        ->assertSee('Every scheduled task runs on its normal schedule');
});

it('lists the deferrable tasks and the ones currently deferred', function (): void {
    openSiteHealthAsAdmin();
    fixtureSettingsHost([]);
    config()->set('capell.health.host.deferrable_tasks', ['app:reports', 'app:cleanup']);
    resolve(HostMonitorStore::class)->markCatchUpPending('app:reports', now()->getTimestamp());

    Livewire::test(SiteHealthPage::class)
        ->assertSee(__('capell-admin::server-settings.deferrable_listed', ['tasks' => 'app:reports, app:cleanup']))
        ->assertSee(__('capell-admin::server-settings.deferrable_deferred', ['tasks' => 'app:reports']));
});
