<?php

declare(strict_types=1);

use Capell\Admin\Actions\InstallImpersonationPermissionAction;
use Capell\Admin\Filament\Resources\Users\Pages\ListUsers;
use Capell\Admin\Settings\AdminSettings;
use Capell\Admin\Tests\Fixtures\Models\RealSiteScopedAdminResourceUser;
use Capell\Admin\Tests\Support\SiteScopedUserFactory;
use Capell\Core\Models\Site;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
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

function impersonateActionName(): string
{
    return Impersonate::getDefaultName() ?? throw new RuntimeException('Expected impersonate action to have a default name.');
}

it('shows the impersonate action when the logged-in user has the permission', function (): void {
    $impersonator = SiteScopedUserFactory::create(
        test()->site,
        [
            InstallImpersonationPermissionAction::PERMISSION_IMPERSONATE,
            'ViewAny:User',
        ],
    );
    $target = SiteScopedUserFactory::create(test()->site);

    test()->actingAs($impersonator);

    Livewire::test(ListUsers::class)
        ->assertSuccessful()
        ->assertTableActionExists(
            impersonateActionName(),
            fn (Impersonate $action): bool => $action->getLabel() === __('capell-admin::button.act_as_owner'),
            $target,
        )
        ->assertTableActionVisible(impersonateActionName(), $target);
});

it('hides the impersonate action when support actions are disabled', function (): void {
    $settings = AdminSettings::instance();
    $settings->enable_support_actions_user_bridge = false;
    $settings->save();

    $impersonator = SiteScopedUserFactory::create(
        test()->site,
        [
            InstallImpersonationPermissionAction::PERMISSION_IMPERSONATE,
            'ViewAny:User',
        ],
    );
    $target = SiteScopedUserFactory::create(test()->site);

    test()->actingAs($impersonator);

    Livewire::test(ListUsers::class)
        ->assertSuccessful()
        ->assertTableActionHidden(impersonateActionName(), $target);
});

it('hides the impersonate action when the logged-in user lacks the permission', function (): void {
    $plainUser = SiteScopedUserFactory::create(test()->site, ['ViewAny:User']);
    $target = SiteScopedUserFactory::create(test()->site);

    test()->actingAs($plainUser);

    Livewire::test(ListUsers::class)
        ->assertSuccessful()
        ->assertTableActionHidden(impersonateActionName(), $target);
});

it('hides the impersonate action when the target user holds the impersonate permission', function (): void {
    $impersonator = SiteScopedUserFactory::create(
        test()->site,
        [
            InstallImpersonationPermissionAction::PERMISSION_IMPERSONATE,
            'ViewAny:User',
        ],
    );
    $adminTarget = SiteScopedUserFactory::create(test()->site);
    $adminTarget->givePermissionTo(InstallImpersonationPermissionAction::PERMISSION_IMPERSONATE);

    test()->actingAs($impersonator);

    Livewire::test(ListUsers::class)
        ->assertSuccessful()
        ->assertTableActionHidden(impersonateActionName(), $adminTarget);
});

it('does not expose impersonation targets outside the actors site despite having both permissions', function (): void {
    $impersonator = SiteScopedUserFactory::create(test()->site, [
        InstallImpersonationPermissionAction::PERMISSION_IMPERSONATE,
        'ViewAny:User',
    ]);
    $local = SiteScopedUserFactory::create(test()->site);
    $foreign = SiteScopedUserFactory::create(Site::factory()->createOne());
    test()->actingAs($impersonator);

    Livewire::test(ListUsers::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$impersonator, $local])
        ->assertCanNotSeeTableRecords([$foreign])
        ->assertTableActionVisible(impersonateActionName(), $local);
});

it('denies the user list to an impersonator without user view permission', function (): void {
    $impersonator = SiteScopedUserFactory::create(test()->site, [
        InstallImpersonationPermissionAction::PERMISSION_IMPERSONATE,
    ]);
    test()->actingAs($impersonator);

    Livewire::test(ListUsers::class)->assertForbidden();
});
