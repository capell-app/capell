<?php

declare(strict_types=1);

use Capell\Admin\Filament\Resources\Pages\Pages\EditPage;
use Capell\Admin\Filament\Resources\Pages\Pages\ListPages;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Page;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    // Publishing's child component currently resolves only live records.
    // Isolate that unrelated panel while exercising EditPage's real restore action.
    view()->getFinder()->prependNamespace('capell-admin', __DIR__ . '/../../../../../Fixtures/views/restore');
});

it('refuses the whole restore cascade from page resources for role restricted relatives', function (string $surface, string $deniedRelation): void {
    test()->actingAsAdmin();
    $parent = Page::factory()->createOne();
    $child = Page::factory()->site($parent->site)->createOne(['layout_id' => $parent->layout_id]);
    $child->appendToNode($parent)->save();
    $sibling = Page::factory()->site($parent->site)->createOne(['blueprint_id' => $parent->blueprint_id, 'layout_id' => $parent->layout_id]);
    $sibling->appendToNode($parent)->save();
    $selected = $deniedRelation === 'child' ? $parent : $child;
    $denied = $deniedRelation === 'child' ? $child : $parent;
    $restrictedType = Blueprint::factory()->page()->createOne();
    $restrictedType->roleRestrictions()->create(['role_id' => Role::findOrCreate('restricted-restorer', 'web')->id]);
    $denied->update(['blueprint_id' => $restrictedType->id]);
    $parent->delete();

    test()->actingAsUser();
    $actor = test()->authenticatedUser();
    $actor->assignedSiteIds = collect([$parent->site_id]);
    foreach (['ViewAny:Page', 'View:Page', 'Update:Page', 'Restore:Page', 'RestoreAny:Page'] as $name) {
        $actor->givePermissionTo(Permission::findOrCreate($name, 'web'));
    }

    Gate::before(function (mixed $user, string $ability, array $arguments): ?bool {
        if (($arguments[0] ?? null) instanceof Page) {
            $arguments[0]->loadMissing(['blueprint.roleRestrictions', 'site']);
        }

        return null;
    });

    expect(Gate::allows('restore', $selected->fresh()))->toBeTrue()
        ->and(Gate::allows('restore', $denied->fresh()))->toBeFalse();

    if ($surface === 'list') {
        Livewire::test(ListPages::class)
            ->filterTable('trashed', true)
            ->assertCanSeeTableRecords([$selected])
            ->callTableBulkAction('restore', [$selected]);
    } else {
        Livewire::test(EditPage::class, ['record' => $selected->getRouteKey()])
            ->assertSuccessful()
            ->callAction('restore');
    }

    expect(Page::onlyTrashed()->whereKey([$parent->id, $child->id, $sibling->id])->count())->toBe(3);
})->with(['list', 'edit'])->with(['child', 'ancestor']);

it('restores an authorised whole cascade from page resources', function (string $surface): void {
    test()->actingAsAdmin();
    $parent = Page::factory()->createOne();
    $child = Page::factory()->site($parent->site)->createOne(['blueprint_id' => $parent->blueprint_id, 'layout_id' => $parent->layout_id]);
    $child->appendToNode($parent)->save();
    $parent->delete();

    if ($surface === 'list') {
        Livewire::test(ListPages::class)->filterTable('trashed', true)->callTableBulkAction('restore', [$parent]);
    } else {
        Livewire::test(EditPage::class, ['record' => $parent->getRouteKey()])->callAction('restore');
    }

    expect(Page::onlyTrashed()->whereKey([$parent->id, $child->id])->count())->toBe(0);
})->with(['list', 'edit']);
