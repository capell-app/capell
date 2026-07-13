<?php

declare(strict_types=1);

use Capell\Admin\Actions\InstallImpersonationPermissionAction;
use Capell\Admin\Enums\ResourceEnum;
use Capell\Admin\Filament\Resources\Users\Pages\ListUsers;
use Capell\Core\Database\Factories\UserFactory;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use STS\FilamentImpersonate\Actions\Impersonate;

uses(CreatesAdminUser::class)
    ->group('user');

beforeEach(function (): void {
    InstallImpersonationPermissionAction::run();
    Permission::findOrCreate(ResourceEnum::User->permission('view_any'), 'web');
});

it('hides the impersonate action when the target user is a super admin', function (): void {
    $impersonator = test()->createUserWithPermission(
        [
            InstallImpersonationPermissionAction::PERMISSION_IMPERSONATE,
            ResourceEnum::User->permission('view_any'),
        ],
    );

    $superAdminTarget = UserFactory::new()->createOne();
    $superAdminRoleName = (string) config('filament-shield.super_admin.name', 'super_admin');
    Role::findOrCreate($superAdminRoleName, 'web');
    $superAdminTarget->assignRole($superAdminRoleName);

    test()->actingAs($impersonator);

    Livewire::test(ListUsers::class)
        ->assertTableActionHidden(
            Impersonate::getDefaultName() ?? 'impersonate',
            $superAdminTarget,
        );
});

it('protects a global super admin target while another site scope is active', function (): void {
    $superAdminTarget = UserFactory::new()->createOne();
    $superAdminRoleName = (string) config('filament-shield.super_admin.name', 'super_admin');
    $superAdminTarget->assignRole(Role::findOrCreate($superAdminRoleName, 'web'));

    resolve(PermissionRegistrar::class)->setPermissionsTeamId(999999);

    expect($superAdminTarget->canBeImpersonated())->toBeFalse();
});
