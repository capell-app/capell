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
 * only supported mechanism for making a page inaccessible.
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
});

// ---------------------------------------------------------------------------
// viewAny
// ---------------------------------------------------------------------------

it('denies viewAny when the user has no page permissions', function (): void {
    expect($this->user->can('viewAny', Page::class))->toBeFalse();
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

it('allows viewAny when the user has view_any permission', function (): void {
    $this->user->givePermissionTo('ViewAny:Page');

    expect($this->user->can('viewAny', Page::class))->toBeTrue();
});

it('allows viewAny when the user has only the view permission', function (): void {
    $this->user->givePermissionTo('View:Page');

    expect($this->user->can('viewAny', Page::class))->toBeTrue();
});

// ---------------------------------------------------------------------------
// export
// ---------------------------------------------------------------------------

it('denies export when the user has no page export permission', function (): void {
    expect($this->user->can('export', Page::class))->toBeFalse()
        ->and($this->user->can('export', $this->page))->toBeFalse();
});

it('allows export when the user has the exchanger page export permission', function (): void {
    $this->user->givePermissionTo(CapellPermission::ExportPage->name());

    expect($this->user->can('export', Page::class))->toBeTrue()
        ->and($this->user->can('export', $this->page))->toBeTrue();
});

it('denies export when the user has export permission but the page is role-restricted', function (): void {
    $type = Blueprint::factory()->page()->create();
    $restrictedPage = Page::factory()->for($this->site)->create(['blueprint_id' => $type->id]);
    $role = Role::create(['name' => 'restricted-exporter', 'guard_name' => 'web']);
    $type->roleRestrictions()->create(['role_id' => $role->id]);

    $this->user->givePermissionTo(CapellPermission::ExportPage->name());

    expect($this->user->can('export', $restrictedPage))->toBeFalse();
});

// ---------------------------------------------------------------------------
// view
// ---------------------------------------------------------------------------

it('denies view when the user has no page permissions', function (): void {
    expect($this->user->can('view', $this->page))->toBeFalse();
});

it('allows view when the user has view permission and the page is not restricted', function (): void {
    $this->user->givePermissionTo('View:Page');

    expect($this->user->can('view', $this->page))->toBeTrue();
});

it('allows view when the user has view_any permission and the page is not restricted', function (): void {
    $this->user->givePermissionTo('ViewAny:Page');

    expect($this->user->can('view', $this->page))->toBeTrue();
});

it('denies view when the user has view permission but the page type is role-restricted and the user lacks the role', function (): void {
    $type = Blueprint::factory()->page()->create();
    $restrictedPage = Page::factory()->for($this->site)->create(['blueprint_id' => $type->id]);
    $role = Role::create(['name' => 'restricted-viewer', 'guard_name' => 'web']);
    $type->roleRestrictions()->create(['role_id' => $role->id]);

    $this->user->givePermissionTo('View:Page');

    expect($this->user->can('view', $restrictedPage))->toBeFalse();
});

// ---------------------------------------------------------------------------
// create
// ---------------------------------------------------------------------------

it('denies create when the user has no page permissions', function (): void {
    expect($this->user->can('create', Page::class))->toBeFalse();
});

it('allows create when the user has create permission', function (): void {
    $this->user->givePermissionTo('Create:Page');

    expect($this->user->can('create', Page::class))->toBeTrue();
});

// ---------------------------------------------------------------------------
// update
// ---------------------------------------------------------------------------

it('denies update when the user has no page permissions', function (): void {
    expect($this->user->can('update', $this->page))->toBeFalse();
});

it('allows update when the user has update permission and the page is not restricted', function (): void {
    $this->user->givePermissionTo('Update:Page');

    expect($this->user->can('update', $this->page))->toBeTrue();
});

it('denies update when the user has update permission but the page type is role-restricted and the user lacks the role', function (): void {
    $type = Blueprint::factory()->page()->create();
    $restrictedPage = Page::factory()->for($this->site)->create(['blueprint_id' => $type->id]);
    $role = Role::create(['name' => 'restricted-editor', 'guard_name' => 'web']);
    $type->roleRestrictions()->create(['role_id' => $role->id]);

    $this->user->givePermissionTo('Update:Page');

    expect($this->user->can('update', $restrictedPage))->toBeFalse();
});

// ---------------------------------------------------------------------------
// editContent
// ---------------------------------------------------------------------------

it('allows editContent when the user has edit_content permission and the page is not restricted', function (): void {
    $this->user->givePermissionTo('EditContent:Page');

    expect($this->user->can('editContent', $this->page))->toBeTrue();
});

it('allows editContent when the user only has legacy update permission and the page is not restricted', function (): void {
    $this->user->givePermissionTo('Update:Page');

    expect($this->user->can('editContent', $this->page))->toBeTrue();
});

it('denies editContent when the user has edit_content permission but the page type is role-restricted and the user lacks the role', function (): void {
    $type = Blueprint::factory()->page()->create();
    $restrictedPage = Page::factory()->for($this->site)->create(['blueprint_id' => $type->id]);
    $role = Role::create(['name' => 'restricted-content-editor', 'guard_name' => 'web']);
    $type->roleRestrictions()->create(['role_id' => $role->id]);

    $this->user->givePermissionTo('EditContent:Page');

    expect($this->user->can('editContent', $restrictedPage))->toBeFalse();
});

// ---------------------------------------------------------------------------
// editLayout
// ---------------------------------------------------------------------------

it('allows editLayout when the user has edit_layout permission and the page is not restricted', function (): void {
    $this->user->givePermissionTo('EditLayout:Page');

    expect($this->user->can('editLayout', $this->page))->toBeTrue();
});

it('allows editLayout when the user only has legacy update permission and the page is not restricted', function (): void {
    $this->user->givePermissionTo('Update:Page');

    expect($this->user->can('editLayout', $this->page))->toBeTrue();
});

it('denies editLayout when the user has edit_layout permission but the page type is role-restricted and the user lacks the role', function (): void {
    $type = Blueprint::factory()->page()->create();
    $restrictedPage = Page::factory()->for($this->site)->create(['blueprint_id' => $type->id]);
    $role = Role::create(['name' => 'restricted-layout-editor', 'guard_name' => 'web']);
    $type->roleRestrictions()->create(['role_id' => $role->id]);

    $this->user->givePermissionTo('EditLayout:Page');

    expect($this->user->can('editLayout', $restrictedPage))->toBeFalse();
});

// ---------------------------------------------------------------------------
// delete
// ---------------------------------------------------------------------------

it('denies delete when the user has no page permissions', function (): void {
    expect($this->user->can('delete', $this->page))->toBeFalse();
});

it('allows delete when the user has delete permission and the page is not restricted', function (): void {
    $this->user->givePermissionTo('Delete:Page');

    expect($this->user->can('delete', $this->page))->toBeTrue();
});

it('denies delete when the user has delete permission but the page type is role-restricted and the user lacks the role', function (): void {
    $type = Blueprint::factory()->page()->create();
    $restrictedPage = Page::factory()->for($this->site)->create(['blueprint_id' => $type->id]);
    $role = Role::create(['name' => 'restricted-deleter', 'guard_name' => 'web']);
    $type->roleRestrictions()->create(['role_id' => $role->id]);

    $this->user->givePermissionTo('Delete:Page');

    expect($this->user->can('delete', $restrictedPage))->toBeFalse();
});

// ---------------------------------------------------------------------------
// deleteAny
// ---------------------------------------------------------------------------

it('denies deleteAny when the user has no page permissions', function (): void {
    expect($this->user->can('deleteAny', Page::class))->toBeFalse();
});

it('allows deleteAny when the user has delete_any permission', function (): void {
    $this->user->givePermissionTo('DeleteAny:Page');

    expect($this->user->can('deleteAny', Page::class))->toBeTrue();
});

// ---------------------------------------------------------------------------
// restore
// ---------------------------------------------------------------------------

it('denies restore when the user has no page permissions', function (): void {
    expect($this->user->can('restore', $this->page))->toBeFalse();
});

it('allows restore when the user has restore permission and the page is not restricted', function (): void {
    $this->user->givePermissionTo('Restore:Page');

    expect($this->user->can('restore', $this->page))->toBeTrue();
});

it('denies restore when the user has restore permission but the page type is role-restricted and the user lacks the role', function (): void {
    $type = Blueprint::factory()->page()->create();
    $restrictedPage = Page::factory()->for($this->site)->create(['blueprint_id' => $type->id]);
    $role = Role::create(['name' => 'restricted-restorer', 'guard_name' => 'web']);
    $type->roleRestrictions()->create(['role_id' => $role->id]);

    $this->user->givePermissionTo('Restore:Page');

    expect($this->user->can('restore', $restrictedPage))->toBeFalse();
});

// ---------------------------------------------------------------------------
// restoreAny
// ---------------------------------------------------------------------------

it('denies restoreAny when the user has no page permissions', function (): void {
    expect($this->user->can('restoreAny', Page::class))->toBeFalse();
});

it('allows restoreAny when the user has restore_any permission', function (): void {
    $this->user->givePermissionTo('RestoreAny:Page');

    expect($this->user->can('restoreAny', Page::class))->toBeTrue();
});

// ---------------------------------------------------------------------------
// forceDelete
// ---------------------------------------------------------------------------

it('denies forceDelete when the user has no page permissions', function (): void {
    expect($this->user->can('forceDelete', $this->page))->toBeFalse();
});

it('allows forceDelete when the user has force_delete permission and the page is not restricted', function (): void {
    $this->user->givePermissionTo('ForceDelete:Page');

    expect($this->user->can('forceDelete', $this->page))->toBeTrue();
});

it('denies forceDelete when the user has force_delete permission but the page type is role-restricted and the user lacks the role', function (): void {
    $type = Blueprint::factory()->page()->create();
    $restrictedPage = Page::factory()->for($this->site)->create(['blueprint_id' => $type->id]);
    $role = Role::create(['name' => 'restricted-force-deleter', 'guard_name' => 'web']);
    $type->roleRestrictions()->create(['role_id' => $role->id]);

    $this->user->givePermissionTo('ForceDelete:Page');

    expect($this->user->can('forceDelete', $restrictedPage))->toBeFalse();
});

// ---------------------------------------------------------------------------
// forceDeleteAny
// ---------------------------------------------------------------------------

it('denies forceDeleteAny when the user has no page permissions', function (): void {
    expect($this->user->can('forceDeleteAny', Page::class))->toBeFalse();
});

it('allows forceDeleteAny when the user has force_delete_any permission', function (): void {
    $this->user->givePermissionTo('ForceDeleteAny:Page');

    expect($this->user->can('forceDeleteAny', Page::class))->toBeTrue();
});

// ---------------------------------------------------------------------------
// replicate
// ---------------------------------------------------------------------------

it('denies replicate when the user has no page permissions', function (): void {
    expect($this->user->can('replicate', $this->page))->toBeFalse();
});

it('allows replicate when the user has replicate permission and the page is not restricted', function (): void {
    $this->user->givePermissionTo('Replicate:Page');

    expect($this->user->can('replicate', $this->page))->toBeTrue();
});

it('denies replicate when the user has replicate permission but the page type is role-restricted and the user lacks the role', function (): void {
    $type = Blueprint::factory()->page()->create();
    $restrictedPage = Page::factory()->for($this->site)->create(['blueprint_id' => $type->id]);
    $role = Role::create(['name' => 'restricted-replicator', 'guard_name' => 'web']);
    $type->roleRestrictions()->create(['role_id' => $role->id]);

    $this->user->givePermissionTo('Replicate:Page');

    expect($this->user->can('replicate', $restrictedPage))->toBeFalse();
});

// ---------------------------------------------------------------------------
// reorder
// ---------------------------------------------------------------------------

it('denies reorder when the user has no page permissions', function (): void {
    expect($this->user->can('reorder', Page::class))->toBeFalse();
});

it('allows reorder when the user has reorder permission', function (): void {
    $this->user->givePermissionTo('Reorder:Page');

    expect($this->user->can('reorder', Page::class))->toBeTrue();
});

// ---------------------------------------------------------------------------
// manageRestrictions
// ---------------------------------------------------------------------------

it('denies manageRestrictions when the user has no page permissions', function (): void {
    expect($this->user->can('manageRestrictions', Page::class))->toBeFalse();
});

it('allows manageRestrictions when the user has manage_restrictions permission', function (): void {
    $this->user->givePermissionTo(CapellPermission::ManagePageRestrictions->name());

    expect($this->user->can('manageRestrictions', Page::class))->toBeTrue();
});
