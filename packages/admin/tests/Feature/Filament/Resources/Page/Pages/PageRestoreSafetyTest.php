<?php

declare(strict_types=1);

use Capell\Admin\Actions\RestorePageCascadeAction;
use Capell\Admin\Filament\Pages\RecentlyDeletedPage;
use Capell\Admin\Filament\Resources\Pages\Pages\EditPage;
use Capell\Admin\Filament\Resources\Pages\Pages\ListPages;
use Capell\Core\Actions\DeleteSiteAction;
use Capell\Core\Actions\RestoreSiteAction;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $this->freezeTime();
    view()->getFinder()->prependNamespace('capell-admin', __DIR__ . '/../../../../../Fixtures/views/restore');
});

it('refuses every restore entry point when a callback revokes an earlier member', function (string $surface, string $revocation, string $phase): void {
    $parent = Page::factory()->createOne();
    $type = Blueprint::factory()->page()->createOne();
    $first = Page::factory()->parent($parent)->createOne(['blueprint_id' => $type->id]);
    $last = Page::factory()->parent($parent)->createOne();
    $foreign = Site::factory()->createOne();
    $role = Role::findOrCreate('confidential-restoration', 'web');
    if ($surface === 'site') {
        DeleteSiteAction::run($parent->site);
    } else {
        $parent->refresh()->delete();
    }

    $this->actingAsUser();
    $actor = $this->authenticatedUser();
    $actor->assignedSiteIds = collect([$parent->site_id]);
    foreach (['ViewAny:Page', 'View:Page', 'Update:Page', 'Restore:Page', 'RestoreAny:Page', 'View:RecentlyDeletedPage'] as $name) {
        $actor->givePermissionTo(Permission::findOrCreate($name, 'web'));
    }

    Gate::before(static function (mixed $user, string $ability, array $arguments) use ($first, $last, $foreign, $type, $role, $revocation, $phase): ?bool {
        if (($arguments[0] ?? null) instanceof Page) {
            $arguments[0]->loadMissing(['blueprint.roleRestrictions', 'site']);
        }

        if ($phase === 'ability' && $ability === 'restore' && ($arguments[0] ?? null) instanceof Page && $arguments[0]->is($last)) {
            if ($revocation === 'site') {
                Page::withTrashed()->whereKey($first->id)->update(['site_id' => $foreign->id]);
            } else {
                $type->roleRestrictions()->firstOrCreate(['role_id' => $role->id]);
            }
        }

        return null;
    });

    if ($phase === 'listener') {
        Event::listen('eloquent.restoring: ' . Page::class, static function (Page $member) use ($first, $last, $foreign, $type, $role, $revocation): void {
            if ($member->is($last)) {
                if ($revocation === 'site') {
                    Page::withTrashed()->whereKey($first->id)->update(['site_id' => $foreign->id]);
                } else {
                    $type->roleRestrictions()->firstOrCreate(['role_id' => $role->id]);
                }
            }
        });
    }

    match ($surface) {
        'edit' => Livewire::test(EditPage::class, ['record' => $parent->getRouteKey()])->callAction('restore'),
        'list bulk' => Livewire::test(ListPages::class)->filterTable('trashed', true)->callTableBulkAction('restore', [$parent]),
        'recently deleted' => (new RecentlyDeletedPage)->restoreRecord('page', $parent->id),
        'admin action' => RestorePageCascadeAction::run($parent->refresh()),
        'model' => $parent->refresh()->restore(),
        'quiet model' => $parent->refresh()->restoreQuietly(),
        'site' => RestoreSiteAction::run(Site::withTrashed()->whereKey($parent->site_id)->firstOrFail()),
        default => throw new InvalidArgumentException('Unknown restore surface.'),
    };

    expect(Page::onlyTrashed()->whereKey([$parent->id, $first->id, $last->id])->count())->toBe(3)
        ->and($first->fresh()->site_id)->toBe($parent->site_id)
        ->and($type->roleRestrictions()->count())->toBe(0)
        ->and(Page::isBroken())->toBeFalse();
    if ($surface === 'site') {
        expect(Site::withTrashed()->whereKey($parent->site_id)->firstOrFail()->trashed())->toBeTrue();
    }
})->with(['edit', 'list bulk', 'recently deleted', 'admin action', 'model', 'quiet model', 'site'])->with(['site', 'role'])->with(['ability', 'listener'])->group('restore-final');

it('withholds restricted historical titles and existence from restore notices and recently deleted', function (): void {
    $parent = Page::factory()->createOne(['name' => 'Accessible parent']);
    $type = Blueprint::factory()->page()->createOne();
    $type->roleRestrictions()->create(['role_id' => Role::findOrCreate('private-history', 'web')->id]);
    $child = Page::factory()->parent($parent)->createOne(['name' => 'Confidential acquisition plan', 'blueprint_id' => $type->id]);
    Page::query()->whereKey([$parent->id, $child->id])->update(['deleted_at' => now()]);
    $this->actingAsUser();
    $actor = $this->authenticatedUser();
    $actor->assignedSiteIds = collect([$parent->site_id]);
    foreach (['ViewAny:Page', 'View:Page', 'Restore:Page', 'View:RecentlyDeletedPage'] as $name) {
        $actor->givePermissionTo(Permission::findOrCreate($name, 'web'));
    }

    expect(Gate::allows('view', $child->fresh()))->toBeFalse()
        ->and(Gate::allows('restore', $child->fresh()))->toBeFalse();
    (new RecentlyDeletedPage)->restoreRecord('page', $parent->id);
    $notification = array_values(session('filament.notifications', []))[0];
    expect($notification['body'])->toBeNull()
        ->and($parent->fresh()->trashed())->toBeFalse()
        ->and($child->fresh()->trashed())->toBeTrue();
    Livewire::test(RecentlyDeletedPage::class)->assertDontSee('Confidential acquisition plan');
    Gate::before(static function (mixed $user, string $ability, array $arguments): ?bool {
        if (($arguments[0] ?? null) instanceof Page) {
            $arguments[0]->loadMissing(['blueprint.roleRestrictions', 'site']);
        }

        return null;
    });
    Livewire::test(ListPages::class)->filterTable('trashed', true)->assertDontSee('Confidential acquisition plan');
})->group('restore-final');
