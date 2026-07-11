<?php

declare(strict_types=1);

use Capell\Admin\Actions\EnsureCapellPermissionsAction;
use Capell\Admin\Actions\Shield\ResetRoleToDefaultPermissionsAction;
use Capell\Admin\Enums\CapellPermission;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(CreatesAdminUser::class);

beforeEach(function (): void {
    EnsureCapellPermissionsAction::run('web');
    EnsureCapellPermissionsAction::run('admin');
});

it('resets a built-in role to the Capell default permissions', function (): void {
    $role = Role::findOrCreate('admin', 'web');
    $customPermission = Permission::findOrCreate('custom.permission', 'web');
    $role->givePermissionTo($customPermission);

    ResetRoleToDefaultPermissionsAction::run($role);

    $role->refresh();

    expect($role->hasPermissionTo(CapellPermission::ManageSitePermissions->name(), 'web'))->toBeTrue()
        ->and($role->hasPermissionTo('custom.permission', 'web'))->toBeFalse();
});

it('resets super admin permissions for the role guard only', function (): void {
    $role = Role::findOrCreate('super_admin', 'admin');
    Permission::findOrCreate('admin.only.permission', 'admin');
    Permission::findOrCreate('web.only.permission', 'web');

    ResetRoleToDefaultPermissionsAction::run($role);

    $role->refresh();

    expect($role->hasPermissionTo(CapellPermission::ManageSitePermissions->name(), 'admin'))->toBeTrue()
        ->and($role->hasPermissionTo('admin.only.permission', 'admin'))->toBeTrue()
        ->and($role->permissions()->where('guard_name', 'web')->exists())->toBeFalse();
});

it('rejects non built-in roles', function (): void {
    $role = Role::findOrCreate('client_manager', 'web');

    ResetRoleToDefaultPermissionsAction::run($role);
})->throws(InvalidArgumentException::class);

it('forgets cached permissions after resetting defaults', function (): void {
    $permissionRegistrar = resolve(PermissionRegistrar::class);
    $permissionRegistrar->cacheKey = 'capell-test-reset-role-permissions';

    $role = Role::findOrCreate('admin', 'web');

    cache()->forever($permissionRegistrar->cacheKey, ['stale']);

    ResetRoleToDefaultPermissionsAction::run($role);

    expect(cache()->has($permissionRegistrar->cacheKey))->toBeFalse();
});

it('logs reset permission changes using the existing role permission activity shape', function (): void {
    $actor = test()->createUser();
    $role = Role::findOrCreate('admin', 'web');
    $customPermission = Permission::findOrCreate('custom.permission', 'web');
    $role->givePermissionTo($customPermission);

    ResetRoleToDefaultPermissionsAction::run($role, $actor);

    $activity = Activity::query()
        ->where('subject_type', $role::class)
        ->where('subject_id', $role->getKey())
        ->first();

    $activity = expectPresent($activity);

    $properties = expectPresent($activity->properties);
    $attributes = $properties->get('attributes');

    expect($attributes)->toBeArray()
        ->and($activity)->not->toBeNull()
        ->and($activity->event)->toBe('updated')
        ->and($activity->causer_id)->toBe($actor->getKey())
        ->and($activity->causer?->is($actor))->toBeTrue()
        ->and($properties->get('old'))->toBe(['permissions' => ['custom.permission']])
        ->and($attributes['permissions'])->toContain(CapellPermission::ManageSitePermissions->name())
        ->and($attributes['permissions'])->not->toContain('custom.permission');
});
