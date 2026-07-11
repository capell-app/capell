<?php

declare(strict_types=1);

use Capell\Core\Models\Layout;
use Capell\Core\Models\Site;
use Capell\Tests\Fixtures\Models\User;
use Illuminate\Support\Collection as SupportCollection;
use Spatie\Permission\Models\Permission;

/**
 * LayoutPolicy ability matrix tests.
 *
 * Permission names use PascalCase + ':' separator as configured in
 * filament-shield.php (separator: ':', case: 'pascal'). The
 * ResolvesShieldPermission trait applies Str::studly() to the affix,
 * so 'view_any' → 'ViewAny', 'force_delete' → 'ForceDelete', etc.
 *
 * LayoutPolicy applies site ownership checks for users that expose assigned
 * site IDs, while preserving global and site-neutral layout access.
 */
beforeEach(function (): void {
    $abilities = [
        'ViewAny:Layout',
        'View:Layout',
        'Create:Layout',
        'EditContent:Layout',
        'EditLayout:Layout',
        'Update:Layout',
        'Delete:Layout',
        'DeleteAny:Layout',
        'Restore:Layout',
        'ForceDelete:Layout',
        'Replicate:Layout',
        'Reorder:Layout',
    ];

    foreach ($abilities as $name) {
        Permission::findOrCreate($name);
    }

    $this->layout = Layout::factory()->for(Site::factory()->createOne())->create();
    $this->user = User::factory()->createOne();
    $this->user->assignedSiteIds = collect([$this->layout->site_id]);
});

/** @param SupportCollection<int, int> $assignedSiteIds */
function createScopedUserForLayoutPolicyTest(SupportCollection $assignedSiteIds): User
{
    $user = new class extends User
    {
        /** @var SupportCollection<int, int> */
        public SupportCollection $assignedSiteIds;

        protected $table = 'users';

        public function guardName(): string
        {
            return 'web';
        }

        /** @return SupportCollection<int, int> */
        public function getAssignedSiteIds(): SupportCollection
        {
            return $this->assignedSiteIds;
        }

        public function isGlobalAdmin(): bool
        {
            return false;
        }

        public function getMorphClass(): string
        {
            return User::class;
        }
    };

    $user->forceFill([
        'name' => 'Scoped Layout User',
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password'),
    ]);
    $user->save();
    $user->assignedSiteIds = $assignedSiteIds;

    return $user;
}

// ---------------------------------------------------------------------------
// viewAny
// ---------------------------------------------------------------------------

it('denies viewAny when the user has no layout permissions', function (): void {
    expect($this->user->can('viewAny', Layout::class))->toBeFalse();
});

it('allows viewAny when the user has view_any permission', function (): void {
    $this->user->givePermissionTo('ViewAny:Layout');

    expect($this->user->can('viewAny', Layout::class))->toBeTrue();
});

it('allows viewAny when the user has only the view permission', function (): void {
    $this->user->givePermissionTo('View:Layout');

    expect($this->user->can('viewAny', Layout::class))->toBeTrue();
});

// ---------------------------------------------------------------------------
// view
// ---------------------------------------------------------------------------

it('denies view when the user has no layout permissions', function (): void {
    expect($this->user->can('view', $this->layout))->toBeFalse();
});

it('allows view when the user has view permission', function (): void {
    $this->user->givePermissionTo('View:Layout');

    expect($this->user->can('view', $this->layout))->toBeTrue();
});

it('allows view when the user has view_any permission', function (): void {
    $this->user->givePermissionTo('ViewAny:Layout');

    expect($this->user->can('view', $this->layout))->toBeTrue();
});

// ---------------------------------------------------------------------------
// create
// ---------------------------------------------------------------------------

it('denies create when the user has no layout permissions', function (): void {
    expect($this->user->can('create', Layout::class))->toBeFalse();
});

it('allows create when the user has create permission', function (): void {
    $this->user->givePermissionTo('Create:Layout');

    expect($this->user->can('create', Layout::class))->toBeTrue();
});

// ---------------------------------------------------------------------------
// update
// ---------------------------------------------------------------------------

it('denies update when the user has no layout permissions', function (): void {
    expect($this->user->can('update', $this->layout))->toBeFalse();
});

it('allows update when the user has update permission', function (): void {
    $this->user->givePermissionTo('Update:Layout');

    expect($this->user->can('update', $this->layout))->toBeTrue();
});

it('allows update for a site-scoped user when the layout belongs to an assigned site', function (): void {
    $user = createScopedUserForLayoutPolicyTest(collect([$this->layout->site_id]));
    $user->givePermissionTo('Update:Layout');

    expect($user->can('update', $this->layout))->toBeTrue();
});

it('denies update for a site-scoped user when the layout belongs to an unassigned site', function (): void {
    $assignedSite = Site::factory()->createOne();
    $user = createScopedUserForLayoutPolicyTest(collect([$assignedSite->getKey()]));
    $user->givePermissionTo('Update:Layout');

    expect($user->can('update', $this->layout))->toBeFalse();
});

// ---------------------------------------------------------------------------
// editContent
// ---------------------------------------------------------------------------

it('allows editContent when the user has edit_content permission', function (): void {
    $this->user->givePermissionTo('EditContent:Layout');

    expect($this->user->can('editContent', $this->layout))->toBeTrue();
});

it('allows editContent when the user only has legacy update permission', function (): void {
    $this->user->givePermissionTo('Update:Layout');

    expect($this->user->can('editContent', $this->layout))->toBeTrue();
});

// ---------------------------------------------------------------------------
// editLayout
// ---------------------------------------------------------------------------

it('allows editLayout when the user has edit_layout permission', function (): void {
    $this->user->givePermissionTo('EditLayout:Layout');

    expect($this->user->can('editLayout', $this->layout))->toBeTrue();
});

it('allows editLayout when the user only has legacy update permission', function (): void {
    $this->user->givePermissionTo('Update:Layout');

    expect($this->user->can('editLayout', $this->layout))->toBeTrue();
});

// ---------------------------------------------------------------------------
// delete
// ---------------------------------------------------------------------------

it('denies delete when the user has no layout permissions', function (): void {
    expect($this->user->can('delete', $this->layout))->toBeFalse();
});

it('allows delete when the user has delete permission', function (): void {
    $this->user->givePermissionTo('Delete:Layout');

    expect($this->user->can('delete', $this->layout))->toBeTrue();
});

// ---------------------------------------------------------------------------
// deleteAny
// ---------------------------------------------------------------------------

it('denies deleteAny when the user has no layout permissions', function (): void {
    expect($this->user->can('deleteAny', Layout::class))->toBeFalse();
});

it('allows deleteAny when the user has delete_any permission', function (): void {
    $this->user->givePermissionTo('DeleteAny:Layout');

    expect($this->user->can('deleteAny', Layout::class))->toBeTrue();
});

// ---------------------------------------------------------------------------
// restore
// ---------------------------------------------------------------------------

it('denies restore when the user has no layout permissions', function (): void {
    expect($this->user->can('restore', $this->layout))->toBeFalse();
});

it('allows restore when the user has restore permission', function (): void {
    $this->user->givePermissionTo('Restore:Layout');

    expect($this->user->can('restore', $this->layout))->toBeTrue();
});

// ---------------------------------------------------------------------------
// forceDelete
// ---------------------------------------------------------------------------

it('denies forceDelete when the user has no layout permissions', function (): void {
    expect($this->user->can('forceDelete', $this->layout))->toBeFalse();
});

it('allows forceDelete when the user has force_delete permission', function (): void {
    $this->user->givePermissionTo('ForceDelete:Layout');

    expect($this->user->can('forceDelete', $this->layout))->toBeTrue();
});

// ---------------------------------------------------------------------------
// replicate
// ---------------------------------------------------------------------------

it('denies replicate when the user has no layout permissions', function (): void {
    expect($this->user->can('replicate', $this->layout))->toBeFalse();
});

it('allows replicate when the user has replicate permission', function (): void {
    $this->user->givePermissionTo('Replicate:Layout');

    expect($this->user->can('replicate', $this->layout))->toBeTrue();
});

// ---------------------------------------------------------------------------
// reorder
// ---------------------------------------------------------------------------

it('denies reorder when the user has no layout permissions', function (): void {
    expect($this->user->can('reorder', Layout::class))->toBeFalse();
});

it('allows reorder when the user has reorder permission', function (): void {
    $this->user->givePermissionTo('Reorder:Layout');

    expect($this->user->can('reorder', Layout::class))->toBeTrue();
});
