<?php

declare(strict_types=1);

use Capell\Admin\Actions\RestorePageCascadeAction;
use Capell\Admin\Filament\Pages\RecentlyDeletedPage;
use Capell\Admin\Filament\Resources\Pages\PageResource;
use Capell\Admin\Filament\Resources\Pages\Pages\EditPage;
use Capell\Admin\Filament\Resources\Pages\Pages\ListPages;
use Capell\Admin\Support\Navigation\AdminNavigationBadgeCountCache;
use Capell\Core\Actions\DeleteSiteAction;
use Capell\Core\Actions\RestoreSiteAction;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

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
})->with((static function (): array {
    $scenarios = [];
    foreach (['edit', 'list bulk', 'recently deleted', 'admin action', 'model', 'quiet model', 'site'] as $surface) {
        foreach (['site', 'role'] as $revocation) {
            foreach (['ability', 'listener'] as $phase) {
                // Quiet restore intentionally does not run the restoring listener.
                if ($surface === 'quiet model' && $phase === 'listener') {
                    continue;
                }

                $scenarios[$surface . ' ' . $revocation . ' ' . $phase] = [$surface, $revocation, $phase];
            }
        }
    }

    return $scenarios;
})())->group('restore-final');

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

it('supports a cold database permission cache when restoring or listing trash', function (string $operation): void {
    $page = Page::factory()->createOne();
    $page->delete();
    $this->actingAsUser();
    $actor = $this->authenticatedUser();
    $actor->assignedSiteIds = collect([$page->site_id]);
    foreach (['ViewAny:Page', 'View:Page', 'Restore:Page'] as $name) {
        $actor->givePermissionTo(Permission::findOrCreate($name, 'web'));
    }

    config()->set('cache.stores.restore-permissions', [
        'driver' => 'database',
        'connection' => DB::connection()->getName(),
        'table' => 'cache',
        'prefix' => 'restore-permissions-',
    ]);
    config()->set('permission.cache.store', 'restore-permissions');

    $registrar = resolve(PermissionRegistrar::class);
    $registrar->initializeCache();
    $registrar->forgetCachedPermissions();

    if ($operation === 'restore') {
        expect($page->refresh()->restore())->toBeTrue()
            ->and($page->fresh()->trashed())->toBeFalse();
    } else {
        $records = PageResource::getEloquentQuery()->onlyTrashed()->paginate(15);
        expect($records->total())->toBe(1)
            ->and($records->getCollection()->modelKeys())->toBe([$page->id]);
    }

    expect(DB::table('cache')->where('key', 'like', '%spatie.permission.cache')->exists())->toBeTrue();
})->with(['restore', 'trash list']);

it('keeps hidden trash out of limited projections pagination and navigation totals', function (): void {
    $type = Blueprint::factory()->page()->createOne();
    $type->roleRestrictions()->create(['role_id' => Role::findOrCreate('private-counts', 'web')->id]);
    $hidden = Page::factory()->createOne(['blueprint_id' => $type->id]);
    $secondHidden = Page::factory()->site($hidden->site)->createOne(['blueprint_id' => $type->id]);
    $visible = Page::factory()->site($hidden->site)->createOne();
    Page::query()->whereKey([$hidden->id, $secondHidden->id, $visible->id])->update(['deleted_at' => now()]);
    $this->actingAsUser();
    $actor = $this->authenticatedUser();
    $actor->assignedSiteIds = collect([$hidden->site_id]);
    foreach (['ViewAny:Page', 'View:Page'] as $name) {
        $actor->givePermissionTo(Permission::findOrCreate($name, 'web'));
    }

    $records = PageResource::getEloquentQuery()->onlyTrashed()->orderBy('id')->select('pages.id')->limit(1)->get();
    expect($records->modelKeys())->toBe([$visible->id])
        ->and(PageResource::getEloquentQuery()->onlyTrashed()->paginate(1)->total())->toBe(1)
        ->and(resolve(AdminNavigationBadgeCountCache::class)->count(PageResource::class))->toBe(1);
});
