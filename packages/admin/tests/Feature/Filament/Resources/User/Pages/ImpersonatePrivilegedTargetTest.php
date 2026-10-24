<?php

declare(strict_types=1);

use Capell\Admin\Actions\InstallImpersonationPermissionAction;
use Capell\Admin\Filament\Resources\Users\Pages\ListUsers;
use Capell\Admin\Tests\Fixtures\Models\RealSiteScopedAdminResourceUser;
use Capell\Admin\Tests\Support\SiteScopedUserFactory;
use Capell\Core\Models\Site;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use STS\FilamentImpersonate\Actions\Impersonate;

uses(CreatesAdminUser::class)
    ->group('user');

beforeEach(function (): void {
    config(['permission.teams' => true, 'auth.providers.users.model' => RealSiteScopedAdminResourceUser::class]);
    resolve(PermissionRegistrar::class)->teams = true;
    test()->site = Site::factory()->createOne();
    resolve(PermissionRegistrar::class)->setPermissionsTeamId(test()->site->getKey());

    InstallImpersonationPermissionAction::run();
    Permission::findOrCreate('ViewAny:User', 'web');
});

afterEach(function (): void {
    auth()->logout();
    resolve(PermissionRegistrar::class)->setPermissionsTeamId(null);
    resolve(PermissionRegistrar::class)->teams = false;
    resolve(PermissionRegistrar::class)->forgetCachedPermissions();
});

it('hides the impersonate action when the target user is a super admin', function (): void {
    $impersonator = SiteScopedUserFactory::create(
        test()->site,
        [
            InstallImpersonationPermissionAction::PERMISSION_IMPERSONATE,
            'ViewAny:User',
        ],
    );

    $superAdminTarget = SiteScopedUserFactory::create(test()->site);
    $superAdminRoleName = (string) config('filament-shield.super_admin.name', 'super_admin');
    Role::findOrCreate($superAdminRoleName, 'web');
    $superAdminTarget->assignGlobalRole($superAdminRoleName);

    test()->actingAs($impersonator);

    Livewire::test(ListUsers::class)
        ->assertSuccessful()
        ->assertTableActionHidden(
            Impersonate::getDefaultName() ?? 'impersonate',
            $superAdminTarget,
        );
});

it('protects a global super admin target while another site scope is active', function (): void {
    $superAdminTarget = SiteScopedUserFactory::create(test()->site);
    $superAdminRoleName = (string) config('filament-shield.super_admin.name', 'super_admin');
    $superAdminTarget->assignGlobalRole($superAdminRoleName);

    resolve(PermissionRegistrar::class)->setPermissionsTeamId(999999);

    expect($superAdminTarget->canBeImpersonated())->toBeFalse();
});
