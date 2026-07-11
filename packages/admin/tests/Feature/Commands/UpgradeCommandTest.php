<?php

declare(strict_types=1);

use Capell\Admin\Enums\CapellPermission;
use Illuminate\Contracts\Console\Kernel;

use function Pest\Laravel\mock;

use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

it('runs upgrade command successfully', function (): void {
    artisanCommand('capell:admin-upgrade')
        ->assertExitCode(0);
});

it('publishes migrations during upgrade', function (): void {
    artisanCommand('capell:admin-upgrade')
        ->expectsOutput('Admin package upgraded successfully.')
        ->assertExitCode(0);
});

it('syncs new Capell permissions additively during upgrade', function (): void {
    Permission::findOrCreate('custom.client.permission');
    Role::findOrCreate('admin')->givePermissionTo('custom.client.permission');

    artisanCommand('capell:admin-upgrade')
        ->assertExitCode(0);

    $adminRole = Role::findByName('admin');

    expect(Permission::query()->pluck('name')->all())->toContain(
        CapellPermission::ManageSitePermissions->name(),
        CapellPermission::ManagePageRestrictions->name(),
        CapellPermission::ExportSite->name(),
    )
        ->and($adminRole->hasPermissionTo('custom.client.permission', 'web'))->toBeTrue()
        ->and($adminRole->hasPermissionTo(CapellPermission::ManageSitePermissions->name(), 'web'))->toBeTrue()
        ->and($adminRole->hasPermissionTo(CapellPermission::ExportSite->name(), 'web'))->toBeFalse();
});

it('runs all required commands in sequence', function (): void {
    $commandsCalled = [];

    mock(Kernel::class)
        ->shouldReceive('call')
        ->withArgs(function (string $command, array $params = []) use (&$commandsCalled): bool {
            $commandsCalled[] = ['command' => $command, 'params' => $params];

            return true;
        })
        ->andReturn(0);

    artisanCommand('capell:admin-upgrade')
        ->assertExitCode(0);
});
