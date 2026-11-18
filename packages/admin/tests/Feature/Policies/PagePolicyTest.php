<?php

declare(strict_types=1);

use Capell\Admin\Enums\CapellPermission;
use Capell\Admin\Policies\PagePolicy;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Tests\Fixtures\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * PagePolicy ability matrix tests.
 *
 * Permission names use PascalCase + ':' separator as configured in
 * filament-shield.php (separator: ':', case: 'pascal'). The
 * ResolvesShieldPermission trait applies Str::studly() to the affix,
 * so 'view_any' → 'ViewAny', 'force_delete' → 'ForceDelete', etc.
 *
 * Role-restriction tests (isAccessibleByUser = false) set up a page whose
 * type has a role restriction the test user does not satisfy — this is the
 * blueprint restriction layer in addition to the site ownership boundary.
 */
beforeEach(function (): void {
    $abilities = [
        'ViewAny:Page',
        'View:Page',
        'Create:Page',
        'EditContent:Page',
        'EditLayout:Page',
        'Update:Page',
        'Delete:Page',
        'DeleteAny:Page',
        'Restore:Page',
        'RestoreAny:Page',
        'ForceDelete:Page',
        'ForceDeleteAny:Page',
        'Replicate:Page',
        'Reorder:Page',
        CapellPermission::ManagePageRestrictions->name(),
        CapellPermission::ExportPage->name(),
    ];

    foreach ($abilities as $name) {
        Permission::findOrCreate($name);
    }

    $this->site = Site::factory()->createOne();
    $this->page = Page::factory()->for($this->site)->create();
    $this->user = User::factory()->createOne();
    $this->user->assignedSiteIds = collect([(int) $this->site->getKey()]);
});

it('requires a page permission for a collection-level ability', function (string $ability, string $permission): void {
    expect($this->user->can($ability, Page::class))->toBeFalse();

    $this->user->givePermissionTo($permission);

    expect($this->user->can($ability, Page::class))->toBeTrue();
})->with([
    'list with view-any permission' => ['viewAny', 'ViewAny:Page'],
    'list with view permission' => ['viewAny', 'View:Page'],
    'create' => ['create', 'Create:Page'],
    'bulk delete' => ['deleteAny', 'DeleteAny:Page'],
    'bulk restore' => ['restoreAny', 'RestoreAny:Page'],
    'bulk permanent delete' => ['forceDeleteAny', 'ForceDeleteAny:Page'],
    'reorder' => ['reorder', 'Reorder:Page'],
]);

it('requires the page restrictions permission to manage restrictions', function (): void {
    expect($this->user->can('manageRestrictions', Page::class))->toBeFalse();

    $this->user->givePermissionTo(CapellPermission::ManagePageRestrictions->name());

    expect($this->user->can('manageRestrictions', Page::class))->toBeTrue();
});

it('requires the page permission and respects blueprint restrictions on individual records', function (
    string $ability,
    string $permission,
): void {
    $type = Blueprint::factory()->page()->create();
    $restrictedPage = Page::factory()->for($this->site)->create(['blueprint_id' => $type->id]);
    $restrictedRole = Role::findOrCreate('restricted-page-role');
    $type->roleRestrictions()->create(['role_id' => $restrictedRole->getKey()]);

    expect($this->user->can($ability, $this->page))->toBeFalse();

    $this->user->givePermissionTo($permission);

    expect($this->user->can($ability, $this->page))->toBeTrue()
        ->and($this->user->can($ability, $restrictedPage))->toBeFalse();
})->with([
    'view' => ['view', 'View:Page'],
    'update' => ['update', 'Update:Page'],
    'edit content' => ['editContent', 'EditContent:Page'],
    'edit layout' => ['editLayout', 'EditLayout:Page'],
    'delete' => ['delete', 'Delete:Page'],
    'restore' => ['restore', 'Restore:Page'],
    'permanent delete' => ['forceDelete', 'ForceDelete:Page'],
    'replicate' => ['replicate', 'Replicate:Page'],
    'export' => ['export', CapellPermission::ExportPage->name()],
]);

it('allows record viewing with the collection-view permission alone', function (): void {
    $this->user->givePermissionTo('ViewAny:Page');

    expect($this->user->can('view', $this->page))->toBeTrue();
});

it('allows editing page content and layout with the legacy update permission alone', function (): void {
    $this->user->givePermissionTo('Update:Page');

    expect($this->user->can('editContent', $this->page))->toBeTrue()
        ->and($this->user->can('editLayout', $this->page))->toBeTrue();
});

it('requires the exchanger export permission for collection export', function (): void {
    expect($this->user->can('export', Page::class))->toBeFalse();

    $this->user->givePermissionTo(CapellPermission::ExportPage->name());

    expect($this->user->can('export', Page::class))->toBeTrue();
});

it('does not treat a site-scoped super admin role as a global policy bypass', function (): void {
    $role = Role::findOrCreate('super_admin');
    DB::table('model_has_roles')->insert([
        'role_id' => $role->getKey(),
        'model_type' => $this->user->getMorphClass(),
        'model_id' => $this->user->getKey(),
        'team_id' => $this->site->getKey(),
    ]);

    $type = Blueprint::factory()->page()->create();
    $restrictedPage = Page::factory()->for($this->site)->create(['blueprint_id' => $type->id]);
    $restrictedRole = Role::findOrCreate('restricted-page-role');
    $type->roleRestrictions()->create(['role_id' => $restrictedRole->getKey()]);

    expect(resolve(PagePolicy::class)->view($this->user, $restrictedPage))->toBeFalse();
});

// ---------------------------------------------------------------------------
// site-scoped cross-site denial
//
// config('permission.teams') is false in production today, so these checks
// are dormant there. This section proves the direct-record behaviour once
// the flag is enabled and model_has_roles.team_id is populated (the
// backfill target): an editor whose role is scoped to one site
// must be denied on a *specific record* belonging to another site, not
// merely see an empty list. Enabling teams here is scoped to this describe
// block only — afterEach restores the registrar and config so the rest of
// the suite (which assumes teams are off) is unaffected.
// ---------------------------------------------------------------------------

afterEach(function (): void {
    resolve(PermissionRegistrar::class)->setPermissionsTeamId(null);
    resolve(PermissionRegistrar::class)->teams = false;
    resolve(PermissionRegistrar::class)->forgetCachedPermissions();
    config(['permission.teams' => false]);
});

it('denies view of a specific page for an editor whose role is scoped to a different site', function (): void {
    config(['permission.teams' => true]);
    resolve(PermissionRegistrar::class)->teams = true;

    $editorsSite = Site::factory()->createOne();
    $role = Role::findOrCreate('editor');
    $role->givePermissionTo('View:Page');

    DB::table('model_has_roles')->insert([
        'role_id' => $role->getKey(),
        'model_type' => $this->user->getMorphClass(),
        'model_id' => $this->user->getKey(),
        'team_id' => $editorsSite->getKey(),
    ]);

    // $this->page belongs to $this->site (set up in the top-level
    // beforeEach) — a different site to the one the editor's role is
    // scoped to. Activate $this->site's team context, the way
    // SetSitePermissionScope would when the request resolves to that
    // site's record.
    resolve(PermissionRegistrar::class)->setPermissionsTeamId($this->site->getKey());
    resolve(PermissionRegistrar::class)->forgetCachedPermissions();

    expect($this->user->can('view', $this->page))->toBeFalse();
});

it('denies update of a specific page for an editor whose role is scoped to a different site', function (): void {
    config(['permission.teams' => true]);
    resolve(PermissionRegistrar::class)->teams = true;

    $editorsSite = Site::factory()->createOne();
    $role = Role::findOrCreate('editor');
    $role->givePermissionTo('Update:Page');

    DB::table('model_has_roles')->insert([
        'role_id' => $role->getKey(),
        'model_type' => $this->user->getMorphClass(),
        'model_id' => $this->user->getKey(),
        'team_id' => $editorsSite->getKey(),
    ]);

    resolve(PermissionRegistrar::class)->setPermissionsTeamId($this->site->getKey());
    resolve(PermissionRegistrar::class)->forgetCachedPermissions();

    expect($this->user->can('update', $this->page))->toBeFalse();
});

it("allows view of a specific page once the active site matches the editor role's assigned site", function (): void {
    config(['permission.teams' => true]);
    resolve(PermissionRegistrar::class)->teams = true;

    $role = Role::findOrCreate('editor');
    $role->givePermissionTo('View:Page');

    DB::table('model_has_roles')->insert([
        'role_id' => $role->getKey(),
        'model_type' => $this->user->getMorphClass(),
        'model_id' => $this->user->getKey(),
        'team_id' => $this->site->getKey(),
    ]);

    resolve(PermissionRegistrar::class)->setPermissionsTeamId($this->site->getKey());
    resolve(PermissionRegistrar::class)->forgetCachedPermissions();

    expect($this->user->can('view', $this->page))->toBeTrue();
});
