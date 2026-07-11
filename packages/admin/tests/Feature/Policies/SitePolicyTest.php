<?php

declare(strict_types=1);

use Capell\Admin\Enums\CapellPermission;
use Capell\Core\Models\Site;
use Capell\Tests\Fixtures\Models\User;
use Spatie\Permission\Models\Permission;

/**
 * SitePolicy ability matrix tests.
 *
 * Permission names use PascalCase + ':' separator as configured in
 * filament-shield.php (separator: ':', case: 'pascal'). The
 * ResolvesShieldPermission trait applies Str::studly() to the affix,
 * so 'view_any' → 'ViewAny', 'update_own' → 'UpdateOwn', etc.
 *
 * The fixture User model does not implement HasSitePermissions, so the
 * getAssignedSiteIds / isGlobalAdmin branches in the policy are never
 * reached in these tests — only the checkPermissionTo paths are exercised.
 */
beforeEach(function (): void {
    $abilities = [
        'ViewAny:Site',
        'View:Site',
        'Create:Site',
        'Update:Site',
        CapellPermission::UpdateOwnSite->name(),
        'Delete:Site',
        'DeleteAny:Site',
        'Restore:Site',
        'ForceDelete:Site',
        CapellPermission::ManageSitePermissions->name(),
        CapellPermission::ExportSite->name(),
    ];

    foreach ($abilities as $name) {
        Permission::findOrCreate($name);
    }

    $this->site = Site::factory()->createOne();
    $this->user = User::factory()->createOne();
});

// ---------------------------------------------------------------------------
// viewAny
// ---------------------------------------------------------------------------

it('denies viewAny when the user has no site permissions', function (): void {
    expect($this->user->can('viewAny', Site::class))->toBeFalse();
});

it('allows viewAny when the user has view_any permission', function (): void {
    $this->user->givePermissionTo('ViewAny:Site');

    expect($this->user->can('viewAny', Site::class))->toBeTrue();
});

// ---------------------------------------------------------------------------
// export
// ---------------------------------------------------------------------------

it('denies export when the user has no site export permission', function (): void {
    expect($this->user->can('export', Site::class))->toBeFalse()
        ->and($this->user->can('export', $this->site))->toBeFalse();
});

it('allows export when the user has the exchanger site export permission', function (): void {
    $this->user->assignedSiteIds = collect([$this->site->getKey()]);
    $this->user->givePermissionTo(CapellPermission::ExportSite->name());

    expect($this->user->can('export', Site::class))->toBeTrue()
        ->and($this->user->can('export', $this->site))->toBeTrue();
});

// ---------------------------------------------------------------------------
// view
// ---------------------------------------------------------------------------

it('denies view when the user has no site permissions', function (): void {
    expect($this->user->can('view', $this->site))->toBeFalse();
});

it('allows view when the user has view_any permission', function (): void {
    $this->user->givePermissionTo('ViewAny:Site');

    expect($this->user->can('view', $this->site))->toBeTrue();
});

// ---------------------------------------------------------------------------
// create
// ---------------------------------------------------------------------------

it('denies create when the user has no site permissions', function (): void {
    expect($this->user->can('create', Site::class))->toBeFalse();
});

it('allows create when the user has create permission', function (): void {
    $this->user->givePermissionTo('Create:Site');

    expect($this->user->can('create', Site::class))->toBeTrue();
});

// ---------------------------------------------------------------------------
// update
// ---------------------------------------------------------------------------

it('denies update when the user has no site permissions', function (): void {
    expect($this->user->can('update', $this->site))->toBeFalse();
});

it('allows update when the user has update permission', function (): void {
    $this->user->givePermissionTo('Update:Site');

    expect($this->user->can('update', $this->site))->toBeTrue();
});

// ---------------------------------------------------------------------------
// delete
// ---------------------------------------------------------------------------

it('denies delete when the user has no site permissions', function (): void {
    expect($this->user->can('delete', $this->site))->toBeFalse();
});

it('allows delete when the user has delete permission', function (): void {
    $this->user->givePermissionTo('Delete:Site');

    expect($this->user->can('delete', $this->site))->toBeTrue();
});

// ---------------------------------------------------------------------------
// deleteAny
// ---------------------------------------------------------------------------

it('denies deleteAny when the user has no site permissions', function (): void {
    expect($this->user->can('deleteAny', Site::class))->toBeFalse();
});

it('allows deleteAny when the user has delete_any permission', function (): void {
    $this->user->givePermissionTo('DeleteAny:Site');

    expect($this->user->can('deleteAny', Site::class))->toBeTrue();
});

// ---------------------------------------------------------------------------
// restore
// ---------------------------------------------------------------------------

it('denies restore when the user has no site permissions', function (): void {
    expect($this->user->can('restore', $this->site))->toBeFalse();
});

it('allows restore when the user has restore permission', function (): void {
    $this->user->givePermissionTo('Restore:Site');

    expect($this->user->can('restore', $this->site))->toBeTrue();
});

// ---------------------------------------------------------------------------
// forceDelete
// ---------------------------------------------------------------------------

it('denies forceDelete when the user has no site permissions', function (): void {
    expect($this->user->can('forceDelete', $this->site))->toBeFalse();
});

it('allows forceDelete when the user has force_delete permission', function (): void {
    $this->user->givePermissionTo('ForceDelete:Site');

    expect($this->user->can('forceDelete', $this->site))->toBeTrue();
});

// ---------------------------------------------------------------------------
// managePermissions
// ---------------------------------------------------------------------------

it('denies managePermissions when the user has no site permissions', function (): void {
    expect($this->user->can('managePermissions', $this->site))->toBeFalse();
});

it('allows managePermissions when the user has manage_permissions permission', function (): void {
    $this->user->givePermissionTo(CapellPermission::ManageSitePermissions->name());

    expect($this->user->can('managePermissions', $this->site))->toBeTrue();
});
