<?php

declare(strict_types=1);

use Capell\Admin\Filament\Resources\Media\Pages\EditMedia;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Media;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;
use Capell\Tests\Fixtures\Models\User;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses()->group('media');

beforeEach(function (): void {
    config()->set('capell.media.model', Media::class);
    config()->set('media-library.media_model', Media::class);

    $this->site = Site::factory()->createOne();
    $role = Role::findOrCreate('media-restricted-role', 'web');
    $blueprint = Blueprint::factory()->page()->create();
    $blueprint->roleRestrictions()->create(['role_id' => $role->getKey()]);

    $this->restrictedPage = Page::factory()->for($this->site)->create(['blueprint_id' => $blueprint->getKey()]);
    $this->openPage = Page::factory()->for($this->site)->create();
    $this->restrictedMedia = Media::factory()->model($this->restrictedPage)->createOne(['name' => 'Restricted image']);
    $this->openMedia = Media::factory()->model($this->openPage)->createOne(['name' => 'Open image']);
});

function mediaEditor(Site $site, bool $withRestrictedRole): User
{
    $actor = User::factory()->createOne();
    $actor->assignedSiteIds = collect([(int) $site->getKey()]);

    foreach (['View:Media', 'ViewAny:Media', 'Update:Media', 'Delete:Media', 'Restore:Media', 'ForceDelete:Media', 'View:Page', 'ViewAny:Page', 'Update:Page'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $actor->givePermissionTo($permission);
    }

    if ($withRestrictedRole) {
        $actor->assignRole('media-restricted-role');
    }

    return $actor;
}

it('refuses media changes on a page a blueprint role restriction closes to the editor', function (string $ability): void {
    $actor = mediaEditor($this->site, withRestrictedRole: false);
    test()->actingAs($actor);

    $restrictedTranslationMedia = Media::factory()
        ->model(Translation::factory()->translatable($this->restrictedPage)->create())
        ->createOne(['name' => 'Restricted translation image']);

    expect(Gate::forUser($actor)->allows($ability, $this->restrictedMedia))->toBeFalse()
        ->and(Gate::forUser($actor)->allows($ability, $restrictedTranslationMedia))->toBeFalse()
        ->and(Gate::forUser($actor)->allows($ability, $this->openMedia))->toBeTrue();
})->with(['update', 'delete', 'restore', 'forceDelete']);

it('refuses to open the edit screen of restricted page media', function (): void {
    test()->actingAs(mediaEditor($this->site, withRestrictedRole: false));

    Livewire::test(EditMedia::class, ['record' => $this->restrictedMedia->getRouteKey()])->assertForbidden();

    expect($this->restrictedMedia->fresh()->name)->toBe('Restricted image');
});

it('still lets an editor in the restricted role change and delete that page media', function (): void {
    $actor = mediaEditor($this->site, withRestrictedRole: true);
    test()->actingAs($actor);

    foreach (['update', 'delete', 'restore', 'forceDelete'] as $ability) {
        expect(Gate::forUser($actor)->allows($ability, $this->restrictedMedia))->toBeTrue();
    }

    Livewire::test(EditMedia::class, ['record' => $this->restrictedMedia->getRouteKey()])->assertSuccessful();
});
