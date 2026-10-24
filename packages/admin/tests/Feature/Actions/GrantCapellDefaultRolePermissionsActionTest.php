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
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    EnsureCapellPermissionsAction::run();
});

it('grants install defaults to built-in roles', function (): void {
    GrantCapellDefaultRolePermissionsAction::run(PermissionSyncMode::Install);

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
});

it('preserves admin access to newly protected global resources through role defaults', function (?PermissionSyncMode $mode): void {
    if ($mode instanceof PermissionSyncMode) {
        GrantCapellDefaultRolePermissionsAction::run($mode);
    } else {
        AssignPermissionsToRole::run(resources: [ResourceEnum::Activity, ResourceEnum::BlockTemplate]);
        SeedDefaultRolesAction::run();
    }

    $admin = Role::findByName('admin');
    $editor = Role::findByName('editor');
    foreach ([ResourceEnum::Activity, ResourceEnum::BlockTemplate] as $resource) {
        foreach (['view_any', 'view'] as $affix) {
            expect($admin->checkPermissionTo($resource->permission($affix)))->toBeTrue()
                ->and($editor->checkPermissionTo($resource->permission($affix)))->toBeFalse();
        }
    }

    test()->actingAsRole('admin');
    expect(ActivityResource::canViewAny())->toBeTrue()
        ->and(BlockTemplateResource::canViewAny())->toBeTrue()
        ->and(BlockTemplateResource::canCreate())->toBeTrue()
        ->and($admin->checkPermissionTo(CapellPermission::RevertActivityLog->name()))->toBeFalse()
        ->and($admin->checkPermissionTo(CapellPermission::DeleteActivityLog->name()))->toBeFalse();

    test()->actingAsRole('editor');
    expect(ActivityResource::canViewAny())->toBeFalse()
        ->and(BlockTemplateResource::canViewAny())->toBeFalse();
})->with([
    'install' => [PermissionSyncMode::Install],
    'upgrade' => [PermissionSyncMode::Upgrade],
    'seed' => [null],
]);

it('adds upgrade defaults without removing existing role permissions', function (): void {
    $customPermission = Permission::findOrCreate('custom.client.permission');
    $adminRole = Role::findOrCreate('admin');
    $adminRole->givePermissionTo($customPermission);

    GrantCapellDefaultRolePermissionsAction::run(PermissionSyncMode::Upgrade);

    $adminRole->refresh();
    $editorRole = Role::findByName('editor');

    expect($adminRole->hasPermissionTo('custom.client.permission', 'web'))->toBeTrue()
        ->and($adminRole->hasPermissionTo(CapellPermission::ManageSitePermissions->name(), 'web'))->toBeTrue()
        ->and($adminRole->hasPermissionTo(CapellPermission::ExportSite->name(), 'web'))->toBeFalse()
        ->and($editorRole->hasPermissionTo(ResourceEnum::PageUrl->permission('view_any'), 'web'))->toBeTrue()
        ->and($editorRole->hasPermissionTo(ResourceEnum::PageUrl->permission('view'), 'web'))->toBeTrue()
        ->and($editorRole->hasPermissionTo(ResourceEnum::PageUrl->permission('create'), 'web'))->toBeTrue()
        ->and($editorRole->hasPermissionTo(ResourceEnum::PageUrl->permission('update'), 'web'))->toBeTrue()
        ->and($editorRole->hasPermissionTo(CapellPermission::RollbackPage->name(), 'web'))->toBeTrue();
});

it('backfills editor page URL permissions during an upgrade', function (): void {
    foreach (['view_any', 'view', 'create', 'update'] as $affix) {
        Permission::create([
            'name' => ResourceEnum::PageUrl->permission($affix),
            'guard_name' => 'web',
        ]);
    }

    GrantCapellDefaultRolePermissionsAction::run(PermissionSyncMode::Upgrade);

    $editorRole = Role::findByName('editor');

    expect($editorRole->hasPermissionTo('ViewAny:PageUrl', 'web'))->toBeTrue()
        ->and($editorRole->hasPermissionTo('View:PageUrl', 'web'))->toBeTrue()
        ->and($editorRole->hasPermissionTo('Create:PageUrl', 'web'))->toBeTrue()
        ->and($editorRole->hasPermissionTo('Update:PageUrl', 'web'))->toBeTrue()
        ->and($editorRole->hasPermissionTo(CapellPermission::RollbackPage->name(), 'web'))->toBeTrue();
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

it('forgets cached permissions after granting defaults', function (): void {
    $registrar = resolve(PermissionRegistrar::class);
    $registrar->cacheKey = 'capell-test-role-permissions';

    cache()->forever($registrar->cacheKey, ['stale']);

    GrantCapellDefaultRolePermissionsAction::run(PermissionSyncMode::Install);

    expect(cache()->has($registrar->cacheKey))->toBeFalse();
});

it('grants upgrade defaults under the role guard independently of the panel guard and remains idempotent', function (string $panelGuard, bool $superAdminViaGate): void {
    config()->set('auth.guards.panel', config('auth.guards.web'));
    config()->set('auth.defaults.guard', 'web');
    config()->set('filament-shield.super_admin.define_via_gate', $superAdminViaGate);
    Filament::getDefaultPanel()->authGuard($panelGuard);
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
    'matching guards' => ['web', true],
    'different panel guard' => ['panel', true],
    'different panel guard with direct super admin grants' => ['panel', false],
]);
