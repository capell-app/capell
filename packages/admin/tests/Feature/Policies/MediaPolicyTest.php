<?php

declare(strict_types=1);

use Capell\Core\Models\Layout;
use Capell\Core\Models\Media;
use Capell\Tests\Fixtures\Models\User;
use Spatie\Permission\Models\Permission;

beforeEach(function (): void {
    foreach ([
        'ViewAny:Media',
        'View:Media',
        'Create:Media',
        'Update:Media',
        'Delete:Media',
        'DeleteAny:Media',
        'Restore:Media',
        'RestoreAny:Media',
        'ForceDelete:Media',
        'ForceDeleteAny:Media',
    ] as $name) {
        Permission::findOrCreate($name);
    }

    $this->user = User::factory()->createOne();
    $this->globalLayoutMedia = Media::factory()
        ->model(Layout::factory()->createOne(['site_id' => null]))
        ->create();
    $this->unsupportedOwnerMedia = Media::factory()
        ->model(User::factory()->createOne())
        ->create();
});

it('allows media listing with either broad or item view permission', function (): void {
    expect($this->user->can('viewAny', Media::class))->toBeFalse();

    $this->user->givePermissionTo('View:Media');

    expect($this->user->can('viewAny', Media::class))->toBeTrue();

    $this->user->revokePermissionTo('View:Media');
    $this->user->givePermissionTo('ViewAny:Media');

    expect($this->user->can('viewAny', Media::class))->toBeTrue();
});

it('requires both media permission and usable media ownership for record actions', function (): void {
    expect($this->user->can('view', $this->globalLayoutMedia))->toBeFalse();

    $this->user->givePermissionTo('View:Media', 'Update:Media', 'Delete:Media', 'Restore:Media', 'ForceDelete:Media');

    expect($this->user->can('view', $this->globalLayoutMedia))->toBeTrue()
        ->and($this->user->can('update', $this->globalLayoutMedia))->toBeTrue()
        ->and($this->user->can('delete', $this->globalLayoutMedia))->toBeTrue()
        ->and($this->user->can('restore', $this->globalLayoutMedia))->toBeTrue()
        ->and($this->user->can('forceDelete', $this->globalLayoutMedia))->toBeTrue()
        ->and($this->user->can('view', $this->unsupportedOwnerMedia))->toBeFalse()
        ->and($this->user->can('update', $this->unsupportedOwnerMedia))->toBeFalse();
});

it('checks standalone media permissions for create and bulk abilities', function (): void {
    expect($this->user->can('create', Media::class))->toBeFalse()
        ->and($this->user->can('deleteAny', Media::class))->toBeFalse()
        ->and($this->user->can('restoreAny', Media::class))->toBeFalse()
        ->and($this->user->can('forceDeleteAny', Media::class))->toBeFalse();

    $this->user->givePermissionTo('Create:Media', 'DeleteAny:Media', 'RestoreAny:Media', 'ForceDeleteAny:Media');

    expect($this->user->can('create', Media::class))->toBeTrue()
        ->and($this->user->can('deleteAny', Media::class))->toBeTrue()
        ->and($this->user->can('restoreAny', Media::class))->toBeTrue()
        ->and($this->user->can('forceDeleteAny', Media::class))->toBeTrue();
});
