<?php

declare(strict_types=1);

use Capell\Admin\Filament\RelationManagers\EventSourcedHistoryRelationManager;
use Capell\Admin\Filament\Resources\Pages\Pages\EditPage;
use Capell\Core\EventSourcing\Events\PageRolledBack;
use Capell\Core\EventSourcing\Rollback\Actions\ApplyRollbackAction;
use Capell\Core\EventSourcing\Rollback\RollbackService;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageRevision;
use Capell\Core\Models\Translation;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(CreatesAdminUser::class)
    ->group('admin');

beforeEach(function (): void {
    test()->actingAsAdmin();
});

function recordPageRevisionForRm(Page $page): void
{
    $page->load(['translations', 'pageUrls']);
    $page->save();
}

it('lists the page revision index', function (): void {
    $page = Page::factory()->createOne();
    recordPageRevisionForRm($page);

    $revisions = PageRevision::query()->where('page_uuid', $page->uuid)->get();

    Livewire::test(EventSourcedHistoryRelationManager::class, [
        'ownerRecord' => $page,
        'pageClass' => EditPage::class,
    ])
        ->assertSuccessful()
        ->assertCanSeeTableRecords($revisions);
});

it('renders a translated change label instead of the projector english string', function (): void {
    $page = Page::factory()->createOne();
    recordPageRevisionForRm($page);

    Livewire::test(EventSourcedHistoryRelationManager::class, [
        'ownerRecord' => $page,
        'pageClass' => EditPage::class,
    ])
        ->assertSuccessful()
        ->assertSee(__('capell-admin::event-sourcing.change_revision'))
        ->assertDontSee('Revision recorded');
});

it('rolls a page back to an earlier revision from the timeline', function (): void {
    $page = Page::factory()->createOne();
    $language = Language::factory()->createOne();

    $translation = Translation::factory()->translatable($page)->language($language)
        ->create(['title' => 'First', 'content' => '<p>first</p>']);
    recordPageRevisionForRm($page);

    $targetVersion = resolve(RollbackService::class)->currentVersion($page->uuid);
    $targetRevision = PageRevision::query()
        ->where('page_uuid', $page->uuid)
        ->where('version', $targetVersion)
        ->firstOrFail();

    $translation->forceFill(['title' => 'Second', 'content' => '<p>second</p>'])->save();
    recordPageRevisionForRm($page);

    Livewire::test(EventSourcedHistoryRelationManager::class, [
        'ownerRecord' => $page,
        'pageClass' => EditPage::class,
    ])
        ->callAction(TestAction::make('rollback')->table($targetRevision))
        ->assertNotified();

    expect($translation->fresh()->title)->toBe('First');
});

it('does not record a rollback event when rolling back to the current version', function (): void {
    $page = Page::factory()->createOne();
    recordPageRevisionForRm($page);

    $currentVersion = resolve(RollbackService::class)->currentVersion($page->uuid);
    $currentRevision = PageRevision::query()
        ->where('page_uuid', $page->uuid)
        ->where('version', $currentVersion)
        ->firstOrFail();

    // The current row carries no restore action at all, so a forged call to it
    // is rejected before it can record a pointless event.
    Livewire::test(EventSourcedHistoryRelationManager::class, [
        'ownerRecord' => $page,
        'pageClass' => EditPage::class,
    ])
        ->assertActionHidden(TestAction::make('rollback')->table($currentRevision));

    expect(DB::table('stored_events')
        ->where('aggregate_uuid', $page->uuid)
        ->where('event_class', PageRolledBack::class)
        ->count())->toBe(0);
});

it('frames older rows as roll back, the head as current, and undone-newer rows as roll forward', function (): void {
    $page = Page::factory()->createOne();
    $language = Language::factory()->createOne();

    $translation = Translation::factory()->translatable($page)->language($language)
        ->create(['title' => 'First', 'content' => '<p>first</p>']);
    recordPageRevisionForRm($page);
    $firstVersion = resolve(RollbackService::class)->currentVersion($page->uuid);

    $translation->forceFill(['title' => 'Second', 'content' => '<p>second</p>'])->save();
    recordPageRevisionForRm($page);
    $secondVersion = resolve(RollbackService::class)->currentVersion($page->uuid);

    $firstRevision = PageRevision::query()->where('page_uuid', $page->uuid)
        ->where('version', $firstVersion)->firstOrFail();
    $secondRevision = PageRevision::query()->where('page_uuid', $page->uuid)
        ->where('version', $secondVersion)->firstOrFail();

    // Before any rollback: the older row restores backward, the head is current.
    Livewire::test(EventSourcedHistoryRelationManager::class, [
        'ownerRecord' => $page,
        'pageClass' => EditPage::class,
    ])
        ->assertActionVisible(TestAction::make('rollback')->table($firstRevision))
        ->assertActionHidden(TestAction::make('rollback')->table($secondRevision))
        ->assertSee(__('capell-admin::event-sourcing.rollback_current'));

    // After rolling back to the first version, the second version is now
    // *newer* than live content, so its row offers a roll forward (redo).
    ApplyRollbackAction::run($page->fresh(), $firstVersion);

    Livewire::test(EventSourcedHistoryRelationManager::class, [
        'ownerRecord' => $page->fresh(),
        'pageClass' => EditPage::class,
    ])
        ->assertActionVisible(TestAction::make('rollback')->table($secondRevision))
        ->assertSee(__('capell-admin::event-sourcing.roll_forward_action'));

    expect($translation->fresh()->title)->toBe('First');
});

it('rolls a page forward to undone content from the timeline', function (): void {
    $page = Page::factory()->createOne();
    $language = Language::factory()->createOne();

    $translation = Translation::factory()->translatable($page)->language($language)
        ->create(['title' => 'First', 'content' => '<p>first</p>']);
    recordPageRevisionForRm($page);
    $firstVersion = resolve(RollbackService::class)->currentVersion($page->uuid);

    $translation->forceFill(['title' => 'Second', 'content' => '<p>second</p>'])->save();
    recordPageRevisionForRm($page);
    $secondVersion = resolve(RollbackService::class)->currentVersion($page->uuid);

    $secondRevision = PageRevision::query()->where('page_uuid', $page->uuid)
        ->where('version', $secondVersion)->firstOrFail();

    // Undo back to the first version, then roll forward to the second via the UI.
    ApplyRollbackAction::run($page->fresh(), $firstVersion);
    expect($translation->fresh()->title)->toBe('First');

    Livewire::test(EventSourcedHistoryRelationManager::class, [
        'ownerRecord' => $page->fresh(),
        'pageClass' => EditPage::class,
    ])
        ->callAction(TestAction::make('rollback')->table($secondRevision))
        ->assertNotified();

    expect($translation->fresh()->title)->toBe('Second');

    // Both the undo and the forward are recorded as append-only rollback events.
    expect(DB::table('stored_events')
        ->where('aggregate_uuid', $page->uuid)
        ->where('event_class', PageRolledBack::class)
        ->count())->toBe(2);
});

it('shows the actor name, and System when no actor is recorded', function (): void {
    $page = Page::factory()->createOne();
    recordPageRevisionForRm($page);

    // A revision with no recorded actor — e.g. a console or unauthenticated save.
    PageRevision::query()->create([
        'page_uuid' => $page->uuid,
        'version' => 9999,
        'actor_id' => null,
        'summary' => 'system change',
        'is_rollback' => false,
        'occurred_at' => now(),
    ]);

    Livewire::test(EventSourcedHistoryRelationManager::class, [
        'ownerRecord' => $page,
        'pageClass' => EditPage::class,
    ])
        ->assertSuccessful()
        ->assertSee(test()->authenticatedUser()->name)
        ->assertSee(__('capell-admin::event-sourcing.system_actor'));
});

it('labels normal saves as Edit and rollbacks as Restore', function (): void {
    $page = Page::factory()->createOne();
    $language = Language::factory()->createOne();

    $translation = Translation::factory()->translatable($page)->language($language)
        ->create(['title' => 'First', 'content' => '<p>first</p>']);
    recordPageRevisionForRm($page);
    $firstVersion = resolve(RollbackService::class)->currentVersion($page->uuid);

    $translation->forceFill(['title' => 'Second', 'content' => '<p>second</p>'])->save();
    recordPageRevisionForRm($page);

    ApplyRollbackAction::run($page->fresh(), $firstVersion);

    Livewire::test(EventSourcedHistoryRelationManager::class, [
        'ownerRecord' => $page->fresh(),
        'pageClass' => EditPage::class,
    ])
        ->assertSuccessful()
        ->assertSee(__('capell-admin::event-sourcing.type_edit'))
        ->assertSee(__('capell-admin::event-sourcing.type_restore'));
});

it('reassures that restoring is non-destructive in the modal', function (): void {
    $page = Page::factory()->createOne();
    $language = Language::factory()->createOne();

    $translation = Translation::factory()->translatable($page)->language($language)
        ->create(['title' => 'First', 'content' => '<p>first</p>']);
    recordPageRevisionForRm($page);
    $firstVersion = resolve(RollbackService::class)->currentVersion($page->uuid);

    $translation->forceFill(['title' => 'Second', 'content' => '<p>second</p>'])->save();
    recordPageRevisionForRm($page);

    $firstRevision = PageRevision::query()->where('page_uuid', $page->uuid)
        ->where('version', $firstVersion)->firstOrFail();

    Livewire::test(EventSourcedHistoryRelationManager::class, [
        'ownerRecord' => $page,
        'pageClass' => EditPage::class,
    ])
        ->mountAction(TestAction::make('rollback')->table($firstRevision))
        ->assertMountedActionModalSee('Nothing is deleted');
});

it('hides the restore action from users without rollback permission', function (): void {
    test()->actingAsUser();

    $page = Page::factory()->createOne();
    $language = Language::factory()->createOne();

    $translation = Translation::factory()->translatable($page)->language($language)
        ->create(['title' => 'First', 'content' => '<p>first</p>']);
    recordPageRevisionForRm($page);
    $firstVersion = resolve(RollbackService::class)->currentVersion($page->uuid);

    $translation->forceFill(['title' => 'Second', 'content' => '<p>second</p>'])->save();
    recordPageRevisionForRm($page);

    $firstRevision = PageRevision::query()->where('page_uuid', $page->uuid)
        ->where('version', $firstVersion)->firstOrFail();

    Livewire::test(EventSourcedHistoryRelationManager::class, [
        'ownerRecord' => $page,
        'pageClass' => EditPage::class,
    ])
        ->assertActionHidden(TestAction::make('rollback')->table($firstRevision));
});
