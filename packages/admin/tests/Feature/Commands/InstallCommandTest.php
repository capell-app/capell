<?php

declare(strict_types=1);

use Capell\Admin\Actions\AdminPanelIntegration\IntegrateCapellAdminPanelAction;
use Capell\Admin\Data\AdminPanelIntegration\AdminPanelChangeResultData;
use Capell\Admin\Data\AdminPanelIntegration\AdminPanelSetupResultData;
use Capell\Admin\Data\AdminSurfaceContributionData;
use Capell\Admin\Enums\AdminPanelChangeStatus;
use Capell\Admin\Enums\CapellPermission;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Pages\SiteHealthPage;
use Capell\Core\Support\Migration\MigrationFilesystemInterface;
use Capell\Marketplace\Filament\Pages\MarketplacePage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * @return MigrationFilesystemInterface&object{calls: array<int, array{0: string, 1?: string, 2?: string}>}
 */
function makeMigrationFilesystemStub(): MigrationFilesystemInterface
{
    return new class implements MigrationFilesystemInterface
    {
        /** @var array<int, array{0: string, 1?: string, 2?: string}> */
        public array $calls = [];

        public function fileExists(string $path): bool
        {
            $this->calls[] = ['fileExists', $path];

            return false;
        }

        public function glob(string $pattern): array
        {
            $this->calls[] = ['glob', $pattern];

            return [];
        }

        public function isDir(string $path): bool
        {
            $this->calls[] = ['isDir', $path];

            return true;
        }

        public function isWritable(string $path): bool
        {
            $this->calls[] = ['isWritable', $path];

            return true;
        }

        public function makeDir(string $path): void
        {
            $this->calls[] = ['makeDir', $path];
        }

        public function copy(string $from, string $to): void
        {
            $this->calls[] = ['copy', $from, $to];
        }

        public function delete(string $path): bool
        {
            $this->calls[] = ['delete', $path];

            return true;
        }
    };
}

it('runs install command and does not publish files for capell:publish-migrations', function (): void {
    // Use shared stub with defaults: fileExists=false, isDir=true
    $fakeFileManager = makeMigrationFilesystemStub();

    app()->instance(MigrationFilesystemInterface::class, $fakeFileManager);

    artisanCommand('capell:admin-install')
        ->assertExitCode(0);

    expect($fakeFileManager->calls)->not()->toContain(fn (array $call): bool => $call[0] === 'copy');
});

it('syncs Capell permissions and default roles during admin install', function (): void {
    $fakeFileManager = makeMigrationFilesystemStub();
    app()->instance(MigrationFilesystemInterface::class, $fakeFileManager);
    CapellAdmin::contributeToAdminSurface(AdminSurfaceContributionData::page(MarketplacePage::class));

    artisanCommand('capell:admin-install')
        ->assertExitCode(0);

    $marketplacePermissionName = 'View:' . class_basename(MarketplacePage::class);
    $siteHealthPermissionName = 'View:' . class_basename(SiteHealthPage::class);

    expect(Permission::query()->pluck('name')->all())->toContain(
        CapellPermission::ManageSitePermissions->name(),
        CapellPermission::ManagePageRestrictions->name(),
        CapellPermission::ExportSite->name(),
        $marketplacePermissionName,
        $siteHealthPermissionName,
    )
        ->and(Role::findByName('admin')->hasPermissionTo(CapellPermission::ManageSitePermissions->name(), 'web'))->toBeTrue()
        ->and(Role::findByName('super_admin')->hasPermissionTo(CapellPermission::ExportSite->name(), 'web'))->toBeTrue()
        ->and(Role::findByName('super_admin')->hasPermissionTo($marketplacePermissionName, 'web'))->toBeTrue()
        ->and(Role::findByName('super_admin')->hasPermissionTo($siteHealthPermissionName, 'web'))->toBeTrue();
});

it('leaves admin panel changes for manual setup by default', function (): void {
    $fakeFileManager = makeMigrationFilesystemStub();
    app()->instance(MigrationFilesystemInterface::class, $fakeFileManager);
    $integrateAdminPanelSpy = bindFakeAction(IntegrateCapellAdminPanelAction::class);

    artisanCommand('capell:admin-install')
        ->expectsOutput('Admin panel changes left for manual setup.')
        ->assertExitCode(0);

    expect($integrateAdminPanelSpy->called)->toBeFalse();
});

it('auto applies admin panel changes to the discovered panel when requested', function (): void {
    $fakeFileManager = makeMigrationFilesystemStub();
    app()->instance(MigrationFilesystemInterface::class, $fakeFileManager);
    $integrateAdminPanelSpy = bindFakeAction(IntegrateCapellAdminPanelAction::class, new AdminPanelSetupResultData(
        panelPath: app_path('Providers/Filament/AdminPanelProvider.php'),
        backupPath: null,
        changes: [
            new AdminPanelChangeResultData(
                change: 'Plugin',
                status: AdminPanelChangeStatus::Applied,
                message: 'Applied.',
            ),
        ],
        docsUrl: 'https://capell.test/docs/admin',
    ));

    artisanCommand('capell:admin-install', [
        '--admin-panel-changes' => 'auto',
    ])
        ->assertExitCode(0);

    expect($integrateAdminPanelSpy->called)->toBeTrue();
});

it('fails when admin panel changes mode is not recognised', function (): void {
    $fakeFileManager = makeMigrationFilesystemStub();
    app()->instance(MigrationFilesystemInterface::class, $fakeFileManager);

    artisanCommand('capell:admin-install', [
        '--admin-panel-changes' => 'sometimes',
    ])
        ->expectsOutput('The --admin-panel-changes option must be either auto or manual.')
        ->assertExitCode(1);
});
