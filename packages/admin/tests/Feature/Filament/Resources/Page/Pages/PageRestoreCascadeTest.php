<?php

declare(strict_types=1);

use Capell\Admin\Filament\Resources\Pages\Pages\EditPage;
use Capell\Admin\Filament\Resources\Pages\Pages\ListPages;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Page;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    // Publishing's child component currently resolves only live records.
    // Isolate that unrelated panel while exercising EditPage's real restore action.
    view()->getFinder()->prependNamespace('capell-admin', __DIR__ . '/../../../../../Fixtures/views/restore');
});

it('refuses the whole restore cascade from page resources for role restricted relatives', function (string $surface, string $deniedRelation, bool $crossesSecond): void {
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
    if ($crossesSecond) {
        Page::withTrashed()->whereKey($parent->id)->update(['deleted_at' => $child->fresh()->deleted_at->addSecond()]);
    }

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
})->with(['list', 'edit'])->with(['child', 'ancestor'])->with(['ordinary deletion' => false, 'second boundary' => true]);

it('restores an authorised whole cascade from page resources', function (string $surface, bool $crossesSecond): void {
    test()->actingAsAdmin();
    $parent = Page::factory()->createOne();
    $child = Page::factory()->site($parent->site)->createOne(['blueprint_id' => $parent->blueprint_id, 'layout_id' => $parent->layout_id]);
    $child->appendToNode($parent)->save();
    $parent->delete();
    if ($crossesSecond) {
        Page::withTrashed()->whereKey($parent->id)->update(['deleted_at' => $child->fresh()->deleted_at->addSecond()]);
    }

    if ($surface === 'list') {
        Livewire::test(ListPages::class)->filterTable('trashed', true)->callTableBulkAction('restore', [$parent]);
    } else {
        Livewire::test(EditPage::class, ['record' => $parent->getRouteKey()])->callAction('restore');
    }

    expect(Page::onlyTrashed()->whereKey([$parent->id, $child->id])->count())->toBe(0);
})->with(['list', 'edit'])->with(['ordinary deletion' => false, 'second boundary' => true]);

it('counts overlapping bulk restore selections as successes while retaining cascade refusals', function (bool $includeRefused): void {
    test()->actingAsAdmin();
    $parent = Page::factory()->createOne();
    $child = Page::factory()->site($parent->site)->createOne(['blueprint_id' => $parent->blueprint_id, 'layout_id' => $parent->layout_id]);
    $child->appendToNode($parent)->save();
    $parent->delete();
    $selected = [$parent, $child];
    $refused = null;
    $restrictedChild = null;
    if ($includeRefused) {
        $refused = Page::factory()->site($parent->site)->createOne(['blueprint_id' => $parent->blueprint_id, 'layout_id' => $parent->layout_id]);
        $restrictedType = Blueprint::factory()->page()->createOne();
        $restrictedType->roleRestrictions()->create(['role_id' => Role::findOrCreate('restricted-restorer', 'web')->id]);
        $restrictedChild = Page::factory()->site($parent->site)->createOne(['blueprint_id' => $restrictedType->id, 'layout_id' => $parent->layout_id]);
        $restrictedChild->appendToNode($refused)->save();
        $refused->delete();
        $selected[] = $refused;
    }

    test()->actingAsUser();
    $actor = test()->authenticatedUser();
    $actor->assignedSiteIds = collect([$parent->site_id]);
    foreach (['ViewAny:Page', 'View:Page', 'Restore:Page', 'RestoreAny:Page'] as $name) {
        $actor->givePermissionTo(Permission::findOrCreate($name, 'web'));
    }

    session()->forget('filament.notifications');
    $component = Livewire::test(ListPages::class)
        ->filterTable('trashed', true)
        ->assertCanSeeTableRecords($selected)
        ->callTableBulkAction('restore', $selected);

    expect($parent->fresh()->trashed())->toBeFalse()
        ->and($child->fresh()->trashed())->toBeFalse();
    if ($includeRefused) {
        expect($refused->fresh()->trashed())->toBeTrue()
            ->and($restrictedChild->fresh()->trashed())->toBeTrue();
        $component->assertNotified(Notification::make()->warning()->persistent()
            ->title(trans_choice(
                'filament-actions::restore.multiple.notifications.restored_partial.title',
                2,
                ['count' => 2, 'total' => 3],
            ))
            ->body('<p>' . __('capell-admin::message.restore_cascade_failed_selection') . '</p>'));
    } else {
        $component->assertNotified(Notification::make()->success()->title(__('filament-actions::restore.multiple.notifications.restored.title')));
    }
})->with(['parent and child' => false, 'overlap and refused cascade' => true]);
