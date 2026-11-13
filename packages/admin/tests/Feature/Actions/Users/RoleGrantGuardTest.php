<?php

declare(strict_types=1);

use Capell\Admin\Actions\AssignPermissionsToRole;
use Capell\Admin\Actions\EnsureCapellPermissionsAction;
use Capell\Admin\Actions\SeedDefaultRolesAction;
use Capell\Admin\Enums\ResourceEnum;
use Capell\Admin\Enums\RolePreset;
use Capell\Admin\Support\Users\RoleGrantGuard;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(CreatesAdminUser::class);

beforeEach(function (): void {
    AssignPermissionsToRole::run(resources: ResourceEnum::cases());
    EnsureCapellPermissionsAction::run();
    SeedDefaultRolesAction::run();
    resolve(PermissionRegistrar::class)->forgetCachedPermissions();
});

it('refuses an inviter who can manage users but has no page or media rights', function (): void {
    $actor = test()->createUserWithPermission([
        ResourceEnum::User->permission('view_any'),
        ResourceEnum::User->permission('create'),
    ]);

    expect((new RoleGrantGuard)->canGrant($actor, Role::query()->where('name', RolePreset::ClientEditor->roleName())->firstOrFail()))->toBeFalse();
});

it('allows an inviter who holds every permission the role carries', function (): void {
    $role = Role::query()->where('name', RolePreset::ClientEditor->roleName())->firstOrFail();
    $actor = test()->createUserWithPermission($role->permissions->pluck('name')->all());

    expect((new RoleGrantGuard)->canGrant($actor, $role))->toBeTrue();
});

it('re-checks a role that was widened after the inviter last qualified', function (): void {
    $role = Role::query()->where('name', RolePreset::ClientEditor->roleName())->firstOrFail();
    $actor = test()->createUserWithPermission($role->permissions->pluck('name')->all());

    $role->givePermissionTo(ResourceEnum::Site->permission('update'));
    resolve(PermissionRegistrar::class)->forgetCachedPermissions();

    expect((new RoleGrantGuard)->canGrant($actor->fresh() ?? $actor, $role->fresh() ?? $role))->toBeFalse();
});
