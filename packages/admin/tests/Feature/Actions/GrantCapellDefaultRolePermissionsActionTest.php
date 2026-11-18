<?php

declare(strict_types=1);

use Capell\Admin\Actions\AssignPermissionsToRole;
use Capell\Admin\Actions\EnsureCapellPermissionsAction;
use Capell\Admin\Actions\GrantCapellDefaultRolePermissionsAction;
use Capell\Admin\Actions\SeedDefaultRolesAction;
use Capell\Admin\Actions\Shield\ResolveDefaultGlobalResourcePermissionsAction;
use Capell\Admin\Enums\CapellPermission;
use Capell\Admin\Enums\PermissionSyncMode;
use Capell\Admin\Enums\ResourceEnum;
use Capell\Admin\Filament\Resources\Activities\ActivityResource;
use Capell\Admin\Filament\Resources\BlockTemplates\BlockTemplateResource;
use Capell\Admin\Tests\AdminTestCase;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    EnsureCapellPermissionsAction::run();
});

it('grants install defaults and invalidates cached permissions', function (): void {
    $registrar = resolve(PermissionRegistrar::class);
    $registrar->cacheKey = 'capell-test-role-permissions';

    cache()->forever($registrar->cacheKey, ['stale']);

    GrantCapellDefaultRolePermissionsAction::run(PermissionSyncMode::Install);

    expect(cache()->has($registrar->cacheKey))->toBeFalse();

    $editorRole = Role::findByName('editor');
    $adminRole = Role::findByName('admin');
    $superRole = Role::findByName('super_admin');

    expect($adminRole->hasPermissionTo(CapellPermission::ManageSitePermissions->name(), 'web'))->toBeTrue()
        ->and($adminRole->hasPermissionTo(CapellPermission::ManagePageRestrictions->name(), 'web'))->toBeTrue()
        ->and($adminRole->hasPermissionTo(CapellPermission::ExportSite->name(), 'web'))->toBeFalse()
        ->and($superRole->hasPermissionTo(CapellPermission::ExportSite->name(), 'web'))->toBeTrue()
        ->and($editorRole->hasPermissionTo(CapellPermission::RollbackPage->name(), 'web'))->toBeTrue()
        ->and($editorRole->hasPermissionTo(ResourceEnum::PageUrl->permission('view_any'), 'web'))->toBeTrue()
        ->and($editorRole->hasPermissionTo(ResourceEnum::PageUrl->permission('view'), 'web'))->toBeTrue()
        ->and($editorRole->hasPermissionTo(ResourceEnum::PageUrl->permission('create'), 'web'))->toBeTrue()
        ->and($editorRole->hasPermissionTo(ResourceEnum::PageUrl->permission('update'), 'web'))->toBeTrue()
        ->and($editorRole->hasPermissionTo(CapellPermission::ManageSitePermissions->name(), 'web'))->toBeFalse();

    assertDefaultGlobalResourceAccess($this);
});

function assertDefaultGlobalResourceAccess(AdminTestCase $test): void
{
    $admin = Role::findByName('admin');
    $editor = Role::findByName('editor');
    foreach ([ResourceEnum::Activity, ResourceEnum::BlockTemplate] as $resource) {
        foreach (['view_any', 'view'] as $affix) {
            expect($admin->checkPermissionTo($resource->permission($affix)))->toBeTrue()
                ->and($editor->checkPermissionTo($resource->permission($affix)))->toBeFalse();
        }
    }

    $test->actingAsRole('admin');
    expect(ActivityResource::canViewAny())->toBeTrue()
        ->and(BlockTemplateResource::canViewAny())->toBeTrue()
        ->and(BlockTemplateResource::canCreate())->toBeTrue()
        ->and($admin->checkPermissionTo(CapellPermission::RevertActivityLog->name()))->toBeFalse()
        ->and($admin->checkPermissionTo(CapellPermission::DeleteActivityLog->name()))->toBeFalse();

    $test->actingAsRole('editor');
    expect(ActivityResource::canViewAny())->toBeFalse()
        ->and(BlockTemplateResource::canViewAny())->toBeFalse();
}

it('preserves admin access to global resources when seeding roles', function (): void {
    AssignPermissionsToRole::run(resources: [ResourceEnum::Activity, ResourceEnum::BlockTemplate]);
    SeedDefaultRolesAction::run();

    assertDefaultGlobalResourceAccess($this);
});

it('adds upgrade defaults and backfills editor URL abilities while preserving custom grants', function (): void {
    $customPermission = Permission::findOrCreate('custom.client.permission');
    $adminRole = Role::findOrCreate('admin');
    $adminRole->givePermissionTo($customPermission);

    foreach (['view_any', 'view', 'create', 'update'] as $affix) {
        Permission::create(['name' => ResourceEnum::PageUrl->permission($affix), 'guard_name' => 'web']);
    }

    GrantCapellDefaultRolePermissionsAction::run(PermissionSyncMode::Upgrade);

    $adminRole->refresh();
    $editorRole = Role::findByName('editor');

    expect($adminRole->hasPermissionTo('custom.client.permission', 'web'))->toBeTrue()
        ->and($adminRole->hasPermissionTo(CapellPermission::ManageSitePermissions->name(), 'web'))->toBeTrue()
        ->and($adminRole->hasPermissionTo(CapellPermission::ExportSite->name(), 'web'))->toBeFalse()
        ->and($editorRole->hasPermissionTo('ViewAny:PageUrl', 'web'))->toBeTrue()
        ->and($editorRole->hasPermissionTo('View:PageUrl', 'web'))->toBeTrue()
        ->and($editorRole->hasPermissionTo('Create:PageUrl', 'web'))->toBeTrue()
        ->and($editorRole->hasPermissionTo('Update:PageUrl', 'web'))->toBeTrue()
        ->and($editorRole->hasPermissionTo(CapellPermission::RollbackPage->name(), 'web'))->toBeTrue();

    assertDefaultGlobalResourceAccess($this);
});

it('creates missing built-in roles when granting defaults', function (): void {
    Role::query()
        ->whereIn('name', ['editor', 'admin', 'super_admin'])
        ->delete();

    expect(Role::query()->where('name', 'editor')->exists())->toBeFalse()
        ->and(Role::query()->where('name', 'admin')->exists())->toBeFalse()
        ->and(Role::query()->where('name', 'super_admin')->exists())->toBeFalse();

    GrantCapellDefaultRolePermissionsAction::run(PermissionSyncMode::Upgrade);

    expect(Role::query()->where('name', 'editor')->exists())->toBeTrue()
        ->and(Role::query()->where('name', 'admin')->exists())->toBeTrue()
        ->and(Role::query()->where('name', 'super_admin')->exists())->toBeTrue();
});

it('grants upgrade defaults under the role guard independently of the panel guard and remains idempotent', function (bool $superAdminViaGate): void {
    config()->set('auth.guards.panel', config('auth.guards.web'));
    config()->set('auth.defaults.guard', 'web');
    config()->set('filament-shield.super_admin.define_via_gate', $superAdminViaGate);
    Filament::getDefaultPanel()->authGuard('panel');
    $admin = Role::findOrCreate('admin', 'web');
    $custom = Permission::findOrCreate('custom.client.permission', 'web');
    $admin->givePermissionTo($custom);
    $otherGuardAdmin = Role::findOrCreate('admin', 'panel');
    $otherGuardCustom = Permission::findOrCreate('custom.panel.permission', 'panel');
    $otherGuardAdmin->givePermissionTo($otherGuardCustom);
    $globalPermissions = ResolveDefaultGlobalResourcePermissionsAction::run();

    GrantCapellDefaultRolePermissionsAction::run(PermissionSyncMode::Upgrade);

    expect($globalPermissions)->toHaveCount(50)
        ->and(Permission::query()->where('guard_name', 'web')->whereIn('name', $globalPermissions)->count())->toBe(50)
        ->and($admin->refresh()->permissions()->pluck('name')->all())->toContain(...$globalPermissions)
        ->toContain($custom->name)
        ->and(Role::findByName('editor', 'web')->permissions()->whereIn('name', $globalPermissions)->count())->toBe(0)
        ->and(Permission::query()->where('guard_name', 'panel')->whereIn('name', $globalPermissions)->count())->toBe(0)
        ->and($otherGuardAdmin->refresh()->permissions()->pluck('name')->all())->toBe([$otherGuardCustom->name]);

    $editor = Role::findByName('editor', 'web');
    foreach (['view_any', 'view', 'create', 'update'] as $affix) {
        expect($editor->hasPermissionTo(ResourceEnum::PageUrl->permission($affix), 'web'))->toBeTrue();
    }

    expect($editor->hasPermissionTo(CapellPermission::RollbackPage->name(), 'web'))->toBeTrue();
    if (! $superAdminViaGate) {
        expect(Role::findByName('super_admin', 'web')->permissions()->whereIn('name', $globalPermissions)->count())->toBe(50);
    }

    $permissionCount = Permission::query()->count();
    $pivotCount = DB::table(config('permission.table_names.role_has_permissions'))->count();

    GrantCapellDefaultRolePermissionsAction::run(PermissionSyncMode::Upgrade);

    expect(Permission::query()->count())->toBe($permissionCount)
        ->and(DB::table(config('permission.table_names.role_has_permissions'))->count())->toBe($pivotCount)
        ->and($admin->refresh()->permissions()->whereIn('name', $globalPermissions)->count())->toBe(50);
})->with([
    'super admin via Gate' => true,
    'direct super admin grants' => false,
]);
