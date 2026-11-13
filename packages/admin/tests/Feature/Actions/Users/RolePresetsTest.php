<?php

declare(strict_types=1);

use Capell\Admin\Actions\AssignPermissionsToRole;
use Capell\Admin\Actions\EnsureCapellPermissionsAction;
use Capell\Admin\Actions\SeedDefaultRolesAction;
use Capell\Admin\Actions\Users\SeedRolePresetRolesAction;
use Capell\Admin\Enums\CapellPermission;
use Capell\Admin\Enums\ResourceEnum;
use Capell\Admin\Enums\RolePreset;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(CreatesAdminUser::class);

beforeEach(function (): void {
    AssignPermissionsToRole::run(resources: ResourceEnum::cases());
    EnsureCapellPermissionsAction::run();
});

it('explains every preset in plain words with what the role can and cannot do', function (RolePreset $preset): void {
    expect($preset->label())->not->toBeEmpty()->not->toContain('capell-admin::')
        ->and($preset->description())->not->toBeEmpty()->not->toContain('capell-admin::')
        ->and($preset->canDo())->not->toBeEmpty()
        ->and($preset->cannotDo())->not->toBeEmpty();
})->with(RolePreset::cases());

it('seeds the client editor with content permissions and nothing that changes how the site is set up', function (): void {
    SeedDefaultRolesAction::run();

    $granted = Role::findByName(RolePreset::ClientEditor->roleName())->permissions->pluck('name')->all();

    foreach (['view_any', 'create', 'update', 'delete', 'restore'] as $affix) {
        expect($granted)->toContain(ResourceEnum::Page->permission($affix));
    }

    foreach (['view_any', 'create', 'update', 'delete'] as $affix) {
        expect($granted)->toContain(ResourceEnum::Media->permission($affix));
    }

    expect($granted)->toContain(CapellPermission::RollbackPage->name())
        ->not->toContain(ResourceEnum::Page->permission('edit_layout'))
        ->not->toContain(ResourceEnum::Site->permission('update'))
        ->not->toContain(ResourceEnum::User->permission('view_any'))
        ->not->toContain(ResourceEnum::Language->permission('view_any'))
        ->not->toContain(CapellPermission::ManageExtensions->name())
        ->not->toContain(CapellPermission::ManageSitePermissions->name());
});

it('lets a client editor reach pages and media but not settings, users or roles', function (): void {
    SeedDefaultRolesAction::run();
    resolve(PermissionRegistrar::class)->forgetCachedPermissions();

    $user = test()->createUserWithRole(RolePreset::ClientEditor->roleName());
    $gate = Gate::forUser($user);

    /** @var class-string $userModel */
    $userModel = config('auth.providers.users.model');

    expect($gate->allows('viewAny', Page::class))->toBeTrue()
        ->and($gate->allows('create', Page::class))->toBeTrue()
        ->and($gate->allows('viewAny', Site::class))->toBeFalse()
        ->and($gate->allows('viewAny', $userModel))->toBeFalse()
        ->and($gate->allows('viewAny', Role::class))->toBeFalse();
});

it("creates a missing preset on upgrade but keeps an owner's changes to an existing one", function (): void {
    SeedRolePresetRolesAction::run();

    $role = Role::findByName(RolePreset::ClientEditor->roleName());
    $role->revokePermissionTo(ResourceEnum::Media->permission('delete'));

    SeedRolePresetRolesAction::run(syncExisting: false);

    expect($role->fresh()?->hasPermissionTo(ResourceEnum::Media->permission('delete')))->toBeFalse();

    $role->delete();

    SeedRolePresetRolesAction::run(syncExisting: false);

    expect(Role::findByName(RolePreset::ClientEditor->roleName())->hasPermissionTo(ResourceEnum::Media->permission('delete')))->toBeTrue();
});
