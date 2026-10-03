<?php

declare(strict_types=1);

use Capell\Admin\Actions\CanRestorePageCascadeAction;
use Capell\Admin\Filament\Pages\RecentlyDeletedPage;
use Capell\Core\Actions\CollectPageRestoreCascadeIdsAction;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Media as CapellMedia;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Tests\Fixtures\Models\User;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
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

it('refuses a child deleted during the restore ability checks without restoring any page', function (): void {
    $parent = Page::factory()->createOne();
    $child = Page::factory()->createOne(['site_id' => $parent->site_id, 'blueprint_id' => $parent->blueprint_id, 'layout_id' => $parent->layout_id]);
    $child->appendToNode($parent)->save();
    // Keep the child live until the candidate snapshot has been collected.
    Page::query()->whereKey($parent->id)->update(['deleted_at' => now()]);
    test()->actingAsUser();
    test()->authenticatedUser()->assignedSiteIds = collect([$parent->site_id]);
    $parentChecks = 0;
    $injected = false;
    $transactionLevels = [];
    Gate::before(function (User $user, string $ability, array $arguments) use ($parent, $child, &$parentChecks, &$injected, &$transactionLevels): ?bool {
        if ($ability !== 'restore') {
            return null;
        }

        $transactionLevels[] = $parent->getConnection()->transactionLevel();
        if ($arguments[0]->is($parent) && ++$parentChecks === 2) {
            $child->delete();
            $injected = true;
        }

        return ! $arguments[0]->is($child);
    });

    $initialTransactionLevel = $parent->getConnection()->transactionLevel();
    (new RecentlyDeletedPage)->restoreRecord('page', (int) $parent->id);

    expect($injected)->toBeTrue()
        ->and(Page::onlyTrashed()->whereKey([$parent->id, $child->id])->count())->toBe(2)
        ->and($transactionLevels)->not->toBeEmpty()
        ->and(array_all($transactionLevels, fn (int $level): bool => $level > $initialTransactionLevel))->toBeTrue();
});

it('collects a conservative superset of the native restore with different deletion times', function (): void {
    $ancestor = Page::factory()->createOne();
    $child = Page::factory()->createOne(['site_id' => $ancestor->site_id, 'blueprint_id' => $ancestor->blueprint_id, 'layout_id' => $ancestor->layout_id]);
    $child->appendToNode($ancestor)->save();
    $grandchild = Page::factory()->createOne(['site_id' => $ancestor->site_id, 'blueprint_id' => $ancestor->blueprint_id, 'layout_id' => $ancestor->layout_id]);
    $grandchild->appendToNode($child)->save();
    $sibling = Page::factory()->createOne(['site_id' => $ancestor->site_id, 'blueprint_id' => $ancestor->blueprint_id, 'layout_id' => $ancestor->layout_id]);
    $sibling->appendToNode($ancestor)->save();
    $this->travelTo(today()->setTime(11, 0));
    $child->delete();
    $this->travelTo(now()->setTime(12, 0));
    $ancestor->delete();
    $this->travelBack();
    $child->refresh();

    $collected = CollectPageRestoreCascadeIdsAction::run($child);
    $child->restore();
    $restored = Page::query()->whereKey([$ancestor->id, $child->id, $grandchild->id, $sibling->id])->pluck('id')->all();

    expect($collected)->toContain($ancestor->id, $child->id, $grandchild->id, $sibling->id)
        ->and($restored)->toEqualCanonicalizing([$ancestor->id, $child->id, $sibling->id])
        ->and(array_diff($restored, $collected))->toBe([])
        ->and($grandchild->fresh()->trashed())->toBeTrue();
});

it('collects a deep restore cascade with one descendant query', function (): void {
    $root = Page::factory()->createOne();
    $selected = $root;
    $ids = [$root->id];
    for ($depth = 0; $depth < 8; $depth++) {
        $child = Page::factory()->createOne(['site_id' => $root->site_id, 'blueprint_id' => $root->blueprint_id, 'layout_id' => $root->layout_id]);
        $child->appendToNode($selected)->save();
        $selected = $child;
        $ids[] = $child->id;
    }

    $root->refresh()->delete();
    $selected->refresh();
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $collected = CollectPageRestoreCascadeIdsAction::run($selected);
        $queries = DB::getQueryLog();
    } finally {
        DB::disableQueryLog();
    }

    expect($collected)->toEqualCanonicalizing($ids)
        ->and($queries)->toHaveCount(2);
});

it('retries a deadlocked restore after rereading the selected page and propagates other query failures', function (bool $deadlock): void {
    // A separate in-memory connection has no suite-owned outer transaction, so
    // Laravel can exercise its real top-level deadlock retry and rollback path.
    $source = DB::connection();
    config()->set('database.connections.restore_retry', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]);
    $retry = DB::connection('restore_retry');
    foreach ($source->select("select name, sql from sqlite_master where type = 'table' and name not like 'sqlite_%'") as $table) {
        $retry->statement($table->sql);
        $columns = array_column($source->select('PRAGMA table_info("' . $table->name . '")'), 'name');
        foreach ($source->table($table->name)->get($columns) as $row) {
            $retry->table($table->name)->insert((array) $row);
        }
    }

    $original = DB::getDefaultConnection();
    DB::setDefaultConnection('restore_retry');
    try {
        $page = Page::factory()->createOne();
        $page->delete();
        $attempts = 0;
        $previous = new PDOException($deadlock ? 'Deadlock found when trying to get lock' : 'Invalid query', $deadlock ? 40001 : 42000);
        $previous->errorInfo = [$deadlock ? '40001' : '42000', $deadlock ? 1213 : 1064, $previous->getMessage()];
        $failure = new QueryException('restore_retry', 'select * from pages for update', [], $previous);
        Gate::before(function (mixed $user, string $ability) use (&$attempts, $failure): ?bool {
            throw_if($ability === 'restore' && ++$attempts === 1, $failure);

            return null;
        });
        test()->actingAsUser();
        $actor = test()->authenticatedUser();
        $actor->assignedSiteIds = collect([$page->site_id]);
        $actor->givePermissionTo(Permission::findOrCreate('Restore:Page', 'web'));
        $retry->enableQueryLog();
        $retry->flushQueryLog();

        if ($deadlock) {
            (new RecentlyDeletedPage)->restoreRecord('page', (int) $page->id);
            expect($page->fresh()->trashed())->toBeFalse()
                ->and($attempts)->toBe(3);
        } else {
            expect(fn () => (new RecentlyDeletedPage)->restoreRecord('page', (int) $page->id))->toThrow($failure);
            expect($page->fresh()->trashed())->toBeTrue()
                ->and($attempts)->toBe(1);
        }

        $selectedReads = array_filter($retry->getQueryLog(), fn (array $query): bool => str_contains($query['query'], 'select * from "pages"') && str_contains($query['query'], '"pages"."id" = ?'));
        expect(count($selectedReads))->toBe($deadlock ? 3 : 2);
    } finally {
        DB::setDefaultConnection($original);
        DB::purge('restore_retry');
    }
})->with(['deadlock 1213' => true, 'other query error' => false]);

it('locks the subtree using only primary keys without hydrating descendant models', function (bool $lockForUpdate): void {
    $parent = Page::factory()->createOne();
    $child = Page::factory()->site($parent->site)->createOne(['blueprint_id' => $parent->blueprint_id, 'layout_id' => $parent->layout_id]);
    $child->appendToNode($parent)->save();
    $parent->delete();
    $hydrated = [];
    Page::retrieved(function (Page $page) use (&$hydrated): void {
        $hydrated[] = (int) $page->id;
    });
    DB::enableQueryLog();
    DB::flushQueryLog();
    $ids = DB::transaction(fn (): array => CollectPageRestoreCascadeIdsAction::run($parent, lockForUpdate: $lockForUpdate));
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($ids)->toEqualCanonicalizing([$parent->id, $child->id])
        ->and($hydrated)->not->toContain((int) $child->id)
        ->and($queries)->toHaveCount($lockForUpdate ? 3 : 2);
    expect($queries[1]['query'])->toStartWith('select "id" from "pages"');
})->with(['locking collection' => true, 'ordinary collection' => false]);

it('eager loads site and blueprint once for restore cascade gates', function (): void {
    $parent = Page::factory()->createOne();
    $children = Page::factory()->site($parent->site)->count(5)->create(['blueprint_id' => $parent->blueprint_id, 'layout_id' => $parent->layout_id]);
    foreach ($children as $child) {
        $child->appendToNode($parent)->save();
    }

    $parent->delete();
    test()->actingAsUser();
    $actor = test()->authenticatedUser();
    $actor->assignedSiteIds = collect([$parent->site_id]);
    $actor->givePermissionTo(Permission::findOrCreate('Restore:Page', 'web'));

    $loaded = [];
    Gate::before(function (mixed $user, string $ability, array $arguments) use (&$loaded): ?bool {
        if ($ability === 'restore') {
            $loaded[] = $arguments[0]->relationLoaded('blueprint') && $arguments[0]->relationLoaded('site');
            // Explicitly load missing data so query counts expose multiplication
            // while the ordinary policy still decides every ability.
            $arguments[0]->loadMissing(['blueprint.roleRestrictions', 'site']);
        }

        return null;
    });
    DB::enableQueryLog();
    DB::flushQueryLog();
    expect(DB::transaction(fn (): bool => CanRestorePageCascadeAction::run($parent, lockForUpdate: true)))->toBeTrue();
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    foreach (['blueprints', 'sites'] as $table) {
        $reads = array_filter($queries, fn (array $query): bool => str_contains($query['query'], 'from "' . $table . '"'));
        expect(count($reads))->toBe(1);
    }

    expect($loaded)->toHaveCount(6)->each->toBeTrue();
});
