<?php

declare(strict_types=1);

use Capell\Admin\Actions\CanRestorePageCascadeAction;
use Capell\Admin\Filament\Pages\RecentlyDeletedPage;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Media as CapellMedia;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Tests\Fixtures\Models\User;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(CreatesAdminUser::class)
    ->group('page', 'media');

beforeEach(function (): void {
    test()->actingAsAdmin();

    config()->set('capell.media.model', CapellMedia::class);
    config()->set('media-library.media_model', CapellMedia::class);
});

it('lists and restores recently deleted pages and media', function (): void {
    $page = Page::factory()->createOne(['name' => 'Archived page']);
    $media = CapellMedia::factory()
        ->model($page)
        ->createOne(['name' => 'Archived media', 'file_name' => 'archived-media.jpg']);

    $page->delete();
    $media->delete();

    Livewire::test(RecentlyDeletedPage::class)
        ->assertSuccessful()
        ->assertSee('Archived page')
        ->assertSee('Archived media')
        ->call('restoreRecord', 'page', $page->getKey())
        ->assertNotified(__('capell-admin::message.recently_deleted_restored'))
        ->call('restoreRecord', 'media', $media->getKey())
        ->assertNotified(__('capell-admin::message.recently_deleted_restored'));

    expect($page->fresh()->trashed())->toBeFalse()
        ->and($media->fresh()->trashed())->toBeFalse();
});

it('permanently deletes recently deleted records', function (): void {
    $page = Page::factory()->createOne(['name' => 'Disposable page']);

    $page->delete();

    Livewire::test(RecentlyDeletedPage::class)
        ->assertSuccessful()
        ->call('forceDeleteRecord', 'page', $page->getKey())
        ->assertNotified(__('capell-admin::message.recently_deleted_force_deleted'));

    expect(Page::query()->withTrashed()->find($page->getKey()))->toBeNull();
});

it('refuses permanent deletion of a protected page from recently deleted', function (): void {
    $blueprint = Blueprint::factory()->page()->createOne(['admin' => ['deletable' => false]]);
    $page = Page::factory()->type($blueprint)->createOne();
    $page->delete();

    Livewire::test(RecentlyDeletedPage::class)
        ->call('forceDeleteRecord', 'page', $page->getKey());

    expect(Page::withTrashed()->find($page->getKey()))->not->toBeNull();
});

it('authorises permanent deletion of each recently deleted record', function (): void {
    $page = Page::factory()->createOne();
    $page->delete();

    test()->actingAsUser();
    test()->authenticatedUser()->assignedSiteIds = collect([$page->site_id]);
    Gate::before(fn (User $user, string $ability): ?bool => $ability === 'forceDelete' ? false : null);

    expect(fn () => (new RecentlyDeletedPage)->forceDeleteRecord('page', (int) $page->getKey()))
        ->toThrow(AuthorizationException::class);
    expect(Page::withTrashed()->find($page->getKey()))->not->toBeNull();
});

it('authorises restoration before mutating an accessible recently deleted record', function (string $resource): void {
    $page = Page::factory()->createOne();
    $record = $resource === 'page' ? $page : CapellMedia::factory()->model($page)->createOne();
    $record->delete();

    test()->actingAsUser();
    test()->authenticatedUser()->assignedSiteIds = collect([$page->site_id]);
    test()->authenticatedUser()->givePermissionTo(Permission::findOrCreate('View:RecentlyDeletedPage', 'web'));
    Gate::before(fn (User $user, string $ability): ?bool => $ability === 'restore' ? false : null);

    Livewire::test(RecentlyDeletedPage::class)
        ->call('restoreRecord', $resource, (int) $record->getKey())
        ->assertForbidden();

    expect($record->fresh()?->trashed())->toBeTrue();
})->with(['page', 'media']);

it('lists only accessible deleted pages and media', function (): void {
    $assigned = Site::factory()->create();
    $foreign = Site::factory()->create();
    $ownPage = Page::factory()->site($assigned)->create();
    $foreignPage = Page::factory()->site($foreign)->create();
    $ownMedia = CapellMedia::factory()->model(Page::factory()->site($assigned)->create())->create();
    $foreignMedia = CapellMedia::factory()->model(Page::factory()->site($foreign)->create())->create();
    foreach ([$ownPage, $foreignPage, $ownMedia, $foreignMedia] as $record) {
        $record->delete();
    }

    $actor = User::factory()->create();
    $actor->assignedSiteIds = collect([(int) $assigned->getKey()]);

    test()->actingAs($actor);

    $data = new ReflectionMethod(RecentlyDeletedPage::class, 'getViewData')->invoke(new RecentlyDeletedPage);

    expect($data['groups'][0]['items']->modelKeys())->toBe([$ownPage->getKey()])
        ->and($data['groups'][1]['items']->modelKeys())->toBe([$ownMedia->getKey()]);
});

it('refuses foreign deleted record mutations and permits accessible records', function (string $resource, string $operation): void {
    $assigned = Site::factory()->create();
    $foreign = Site::factory()->create();
    $ownPage = Page::factory()->site($assigned)->create();
    $foreignPage = Page::factory()->site($foreign)->create();
    $own = $resource === 'page' ? $ownPage : CapellMedia::factory()->model($ownPage)->create();
    $other = $resource === 'page' ? $foreignPage : CapellMedia::factory()->model($foreignPage)->create();
    $own->delete();
    $other->delete();
    $actor = User::factory()->create();
    $actor->assignedSiteIds = collect([(int) $assigned->getKey()]);

    test()->actingAs($actor);
    $ability = $operation === 'forceDeleteRecord' ? 'ForceDelete' : 'Restore';
    $permission = Permission::findOrCreate($ability . ':' . ($resource === 'page' ? 'Page' : 'Media'), 'web');
    $actor->givePermissionTo($permission);

    $page = new RecentlyDeletedPage;

    $page->{$operation}($resource, (int) $other->getKey());

    expect($other->fresh())->not->toBeNull()
        ->and($other->fresh()->trashed())->toBeTrue();

    $page->{$operation}($resource, (int) $own->getKey());

    if ($operation === 'restoreRecord') {
        expect($own->fresh()->trashed())->toBeFalse();
    } else {
        expect($own->fresh())->toBeNull();
    }
})->with([
    ['page', 'restoreRecord'],
    ['page', 'forceDeleteRecord'],
    ['media', 'restoreRecord'],
    ['media', 'forceDeleteRecord'],
]);

it('denies deleted record listing and mutations without an actor', function (): void {
    $record = Page::factory()->create();
    $record->delete();

    auth()->logout();
    $page = new RecentlyDeletedPage;
    $data = new ReflectionMethod(RecentlyDeletedPage::class, 'getViewData')->invoke($page);

    expect($data['groups'][0]['items'])->toBeEmpty();

    $page->restoreRecord('page', (int) $record->getKey());
    $page->forceDeleteRecord('page', (int) $record->getKey());

    expect($record->fresh())->not->toBeNull()
        ->and($record->fresh()->trashed())->toBeTrue();
});

it('refuses the whole restore cascade when a related page is denied', function (string $deniedRelation): void {
    $parent = Page::factory()->createOne();
    $child = Page::factory()->createOne(['site_id' => $parent->site_id, 'blueprint_id' => $parent->blueprint_id, 'layout_id' => $parent->layout_id]);
    $child->appendToNode($parent)->save();
    $sibling = Page::factory()->createOne(['site_id' => $parent->site_id, 'blueprint_id' => $parent->blueprint_id, 'layout_id' => $parent->layout_id]);
    $sibling->appendToNode($parent)->save();
    $parent->delete();
    $selected = $deniedRelation === 'child' ? $parent : $child;
    $denied = match ($deniedRelation) {
        'child' => $child,
        'ancestor' => $parent,
        'sibling' => $sibling,
        default => throw new InvalidArgumentException('Unknown denied relation: ' . $deniedRelation),
    };

    test()->actingAsUser();
    $actor = test()->authenticatedUser();
    $actor->assignedSiteIds = collect([$parent->site_id]);
    $actor->givePermissionTo(Permission::findOrCreate('View:RecentlyDeletedPage', 'web'));
    Gate::before(fn (User $user, string $ability, array $arguments): ?bool => $ability === 'restore' ? ! $arguments[0]->is($denied) : null);
    expect(Gate::allows('restore', $selected))->toBeTrue();

    $component = Livewire::test(RecentlyDeletedPage::class)
        ->call('restoreRecord', 'page', (int) $selected->getKey());

    expect(Page::onlyTrashed()->whereKey([$parent->id, $child->id, $sibling->id])->count())->toBe(3);
    $component->assertNotified(__('capell-admin::message.recently_deleted_restore_cascade_denied'));
})->with(['child', 'ancestor', 'sibling']);

it('restores an authorised cascade without requiring permission for older unrelated trash', function (): void {
    $parent = Page::factory()->createOne();
    $child = Page::factory()->createOne(['site_id' => $parent->site_id, 'blueprint_id' => $parent->blueprint_id, 'layout_id' => $parent->layout_id]);
    $child->appendToNode($parent)->save();
    $older = Page::factory()->createOne(['site_id' => $parent->site_id, 'blueprint_id' => $parent->blueprint_id, 'layout_id' => $parent->layout_id]);
    $older->appendToNode($parent)->save();
    $this->travel(-2)->minutes();
    $older->delete();
    $this->travelBack();
    $parent->delete();

    test()->actingAsUser();
    $actor = test()->authenticatedUser();
    $actor->assignedSiteIds = collect([$parent->site_id]);
    Gate::before(fn (User $user, string $ability, array $arguments): ?bool => $ability === 'restore' ? ! $arguments[0]->is($older) : null);

    (new RecentlyDeletedPage)->restoreRecord('page', (int) $child->id);
    expect($parent->fresh()->trashed())->toBeFalse()
        ->and($child->fresh()->trashed())->toBeFalse()
        ->and($older->fresh()->trashed())->toBeTrue();
});

it('refuses a restore cascade check for a page that is no longer trashed', function (): void {
    $page = Page::factory()->createOne();
    expect(CanRestorePageCascadeAction::run($page))->toBeFalse()
        ->and($page->fresh()->trashed())->toBeFalse();
});

it('refuses the whole restore cascade when a related page is outside site access', function (string $foreignRelation): void {
    $parent = Page::factory()->createOne();
    $child = Page::factory()->createOne();
    $child->appendToNode($parent)->save();
    $parent->delete();
    $selected = $foreignRelation === 'child' ? $parent : $child;
    $foreign = $foreignRelation === 'child' ? $child : $parent;

    test()->actingAsUser();
    $actor = test()->authenticatedUser();
    $actor->assignedSiteIds = collect([$selected->site_id]);
    $actor->givePermissionTo(Permission::findOrCreate('View:RecentlyDeletedPage', 'web'));
    Gate::before(fn (User $user, string $ability, array $arguments): ?bool => $ability === 'restore'
        ? $user->assignedSiteIds->contains($arguments[0]->site_id)
        : null);
    expect(Gate::allows('restore', $selected))->toBeTrue()
        ->and(Gate::denies('restore', $foreign))->toBeTrue();

    $component = Livewire::test(RecentlyDeletedPage::class)
        ->call('restoreRecord', 'page', (int) $selected->getKey());

    expect(Page::onlyTrashed()->whereKey([$parent->id, $child->id])->count())->toBe(2);
    $component->assertNotified(__('capell-admin::message.recently_deleted_restore_cascade_denied'));
})->with(['child', 'ancestor']);
