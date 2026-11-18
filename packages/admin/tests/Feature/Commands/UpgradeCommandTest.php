<?php

declare(strict_types=1);

use Capell\Admin\Console\Commands\UpgradeCommand;
use Capell\Admin\Enums\CapellPermission;
use Capell\Core\Actions\Upgrade\RunDatabaseMigrationsAction;
use Capell\Core\Data\MigrationRunResult;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

it('runs the required upgrade stages in order and syncs permissions additively', function (): void {
    Permission::findOrCreate('custom.client.permission');
    Role::findOrCreate('admin')->givePermissionTo('custom.client.permission');

    // AdminSchemaLifecycleTest exercises publication and migration against the real filesystem and schema.
    $stages = [];
    RunDatabaseMigrationsAction::shouldRun()->once()->andReturnUsing(function () use (&$stages): MigrationRunResult {
        $stages[] = 'database migrations';

        return new MigrationRunResult(0, '');
    });

    $command = Mockery::mock(UpgradeCommand::class . '[call]', []);
    $command->shouldReceive('call')->andReturnUsing(function (string $child, array $arguments = []) use (&$stages): int {
        $stages[] = $child;

        if ($child === 'vendor:publish') {
            expect($arguments)->toBe(['--tag' => 'capell-migrations']);
        }

        return 0;
    });
    app()->instance(UpgradeCommand::class, $command);

    artisanCommand('capell:admin-upgrade')
        ->expectsOutput('Admin package upgraded successfully.')
        ->assertSuccessful();

    expect($stages)->toBe([
        'vendor:publish',
        'database migrations',
        'capell:publish-migrations',
        'migrate',
        'shield:generate',
        'filament:clear-cached-components',
        'filament:cache-components',
        'filament:assets',
    ]);

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
