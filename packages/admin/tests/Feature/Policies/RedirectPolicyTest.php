<?php

declare(strict_types=1);

use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Tests\Fixtures\Models\User;
use Spatie\Permission\Models\Permission;

beforeEach(function (): void {
    foreach ([
        'ViewAny:PageUrl',
        'View:PageUrl',
        'Create:PageUrl',
        'Update:PageUrl',
        'Delete:PageUrl',
        'DeleteAny:PageUrl',
        'Restore:PageUrl',
        'RestoreAny:PageUrl',
        'ForceDelete:PageUrl',
        'ForceDeleteAny:PageUrl',
        'Import:PageUrl',
        'Export:PageUrl',
    ] as $permission) {
        Permission::findOrCreate($permission);
    }

    $this->assignedSite = Site::factory()->createOne();
    $this->unassignedSite = Site::factory()->createOne();
    $this->assignedRedirect = PageUrl::factory()
        ->manualRedirect()
        ->site($this->assignedSite)
        ->createOne();
    $this->unassignedRedirect = PageUrl::factory()
        ->manualRedirect()
        ->site($this->unassignedSite)
        ->createOne();
});

it('authorizes redirect viewing through view-any or view permissions within assigned sites', function (): void {
    $viewAnyUser = User::factory()->createOne();
    $viewAnyUser->assignedSiteIds = collect([$this->assignedSite->getKey()]);
    $viewAnyUser->givePermissionTo('ViewAny:PageUrl');

    $viewUser = User::factory()->createOne();
    $viewUser->assignedSiteIds = collect([$this->assignedSite->getKey()]);
    $viewUser->givePermissionTo('View:PageUrl');

    expect($viewAnyUser->can('viewAny', PageUrl::class))->toBeTrue()
        ->and($viewAnyUser->can('view', $this->assignedRedirect))->toBeTrue()
        ->and($viewAnyUser->can('view', $this->unassignedRedirect))->toBeFalse()
        ->and($viewUser->can('viewAny', PageUrl::class))->toBeTrue()
        ->and($viewUser->can('view', $this->assignedRedirect))->toBeTrue()
        ->and($viewUser->can('view', $this->unassignedRedirect))->toBeFalse();
});

it('authorizes redirect mutations only when permission and site scope both pass', function (): void {
    $user = User::factory()->createOne();
    $user->assignedSiteIds = collect([$this->assignedSite->getKey()]);
    $user->givePermissionTo(
        'Create:PageUrl',
        'Update:PageUrl',
        'Delete:PageUrl',
        'DeleteAny:PageUrl',
        'Restore:PageUrl',
        'RestoreAny:PageUrl',
        'ForceDelete:PageUrl',
        'ForceDeleteAny:PageUrl',
        'Import:PageUrl',
        'Export:PageUrl',
    );

    expect($user->can('create', PageUrl::class))->toBeTrue()
        ->and($user->can('update', $this->assignedRedirect))->toBeTrue()
        ->and($user->can('update', $this->unassignedRedirect))->toBeFalse()
        ->and($user->can('delete', $this->assignedRedirect))->toBeTrue()
        ->and($user->can('delete', $this->unassignedRedirect))->toBeFalse()
        ->and($user->can('deleteAny', PageUrl::class))->toBeTrue()
        ->and($user->can('restore', $this->assignedRedirect))->toBeTrue()
        ->and($user->can('restore', $this->unassignedRedirect))->toBeFalse()
        ->and($user->can('restoreAny', PageUrl::class))->toBeTrue()
        ->and($user->can('forceDelete', $this->assignedRedirect))->toBeTrue()
        ->and($user->can('forceDelete', $this->unassignedRedirect))->toBeFalse()
        ->and($user->can('forceDeleteAny', PageUrl::class))->toBeTrue()
        ->and($user->can('import', PageUrl::class))->toBeTrue()
        ->and($user->can('export', PageUrl::class))->toBeTrue();
});
