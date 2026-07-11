<?php

declare(strict_types=1);

use Capell\Admin\Actions\InstallImpersonationPermissionAction;
use Capell\Admin\Enums\ResourceEnum;
use Capell\Admin\Filament\Resources\Users\Pages\ListUsers;
use Capell\Admin\Settings\AdminSettings;
use Capell\Core\Database\Factories\UserFactory;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use STS\FilamentImpersonate\Actions\Impersonate;

uses(CreatesAdminUser::class)
    ->group('user');

beforeEach(function (): void {
    InstallImpersonationPermissionAction::run();
    Permission::findOrCreate(ResourceEnum::User->permission('view_any'), 'web');
});

function impersonateActionName(): string
{
    return Impersonate::getDefaultName() ?? throw new RuntimeException('Expected impersonate action to have a default name.');
}

it('shows the impersonate action when the logged-in user has the permission', function (): void {
    $impersonator = test()->createUserWithPermission(
        [
            InstallImpersonationPermissionAction::PERMISSION_IMPERSONATE,
            ResourceEnum::User->permission('view_any'),
        ],
    );
    $target = UserFactory::new()->createOne();

    test()->actingAs($impersonator);

    Livewire::test(ListUsers::class)
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

    $impersonator = test()->createUserWithPermission(
        [
            InstallImpersonationPermissionAction::PERMISSION_IMPERSONATE,
            ResourceEnum::User->permission('view_any'),
        ],
    );
    $target = UserFactory::new()->createOne();

    test()->actingAs($impersonator);

    Livewire::test(ListUsers::class)
        ->assertTableActionHidden(impersonateActionName(), $target);
});

it('hides the impersonate action when the logged-in user lacks the permission', function (): void {
    $plainUser = test()->createUserWithPermission(ResourceEnum::User->permission('view_any'));
    $target = UserFactory::new()->createOne();

    test()->actingAs($plainUser);

    Livewire::test(ListUsers::class)
        ->assertTableActionHidden(impersonateActionName(), $target);
});

it('hides the impersonate action when the target user holds the impersonate permission', function (): void {
    $impersonator = test()->createUserWithPermission(
        [
            InstallImpersonationPermissionAction::PERMISSION_IMPERSONATE,
            ResourceEnum::User->permission('view_any'),
        ],
    );
    $adminTarget = UserFactory::new()->createOne();
    $adminTarget->givePermissionTo(InstallImpersonationPermissionAction::PERMISSION_IMPERSONATE);

    test()->actingAs($impersonator);

    Livewire::test(ListUsers::class)
        ->assertTableActionHidden(impersonateActionName(), $adminTarget);
});
