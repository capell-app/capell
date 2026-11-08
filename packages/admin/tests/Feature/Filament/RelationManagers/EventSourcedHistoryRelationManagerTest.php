<?php

declare(strict_types=1);

use Capell\Admin\Filament\RelationManagers\EventSourcedHistoryRelationManager;
use Capell\Admin\Filament\Resources\Pages\Pages\EditPage;
use Capell\Core\EventSourcing\Actions\RecordPageVersionAction;
use Capell\Core\EventSourcing\Enums\PageVersionSource;
use Capell\Core\EventSourcing\Events\PageRolledBack;
use Capell\Core\EventSourcing\Rollback\Actions\ApplyRollbackAction;
use Capell\Core\EventSourcing\Rollback\Actions\CompareWithPreviousVersionAction;
use Capell\Core\EventSourcing\Rollback\RollbackService;
use Capell\Core\EventSourcing\Support\PageVersionContext;
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
    $page = Page::factory()->withTranslations()->createOne();
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
    $page = Page::factory()->withTranslations()->createOne();
    recordPageRevisionForRm($page);

    Livewire::test(EventSourcedHistoryRelationManager::class, [
        'ownerRecord' => $page,
        'pageClass' => EditPage::class,
    ])
        ->assertSuccessful()
        ->assertSee(__('capell-admin::event-sourcing.first_version'))
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
    $page = Page::factory()->withTranslations()->createOne();
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
    $page = Page::factory()->withTranslations()->createOne();
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

it('says a version came from a seed or import instead of an anonymous system actor', function (): void {
    $page = Page::factory()->withTranslations()->createOne();

    auth()->logout();
    resolve(PageVersionContext::class)->run(PageVersionSource::Import, null, function () use ($page): void {
        recordPageRevisionForRm($page);
    });
    test()->actingAsAdmin();

    expect(PageRevision::query()->where('page_uuid', $page->uuid)->whereNull('actor_id')->where('source', PageVersionSource::Import->value)->exists())->toBeTrue();

    Livewire::test(EventSourcedHistoryRelationManager::class, [
        'ownerRecord' => $page,
        'pageClass' => EditPage::class,
    ])
        ->assertSuccessful()
        ->assertSee(__('capell-admin::event-sourcing.import_actor'))
        ->assertSee(__('capell-admin::event-sourcing.type_import'));
});

it('shows the label a package gave a published version', function (): void {
    $page = Page::factory()->withTranslations()->createOne();
    RecordPageVersionAction::run($page->fresh(['translations', 'pageUrls']), PageVersionSource::Publish, 'Spring campaign');

    Livewire::test(EventSourcedHistoryRelationManager::class, [
        'ownerRecord' => $page,
        'pageClass' => EditPage::class,
    ])
        ->assertSuccessful()
        ->assertSee('Spring campaign')
        ->assertSee(__('capell-admin::event-sourcing.type_publish'));
});

it('summarises and shows exactly what each version changed', function (): void {
    $page = Page::factory()->createOne();
    $translation = Translation::factory()->translatable($page)->language(Language::factory()->createOne())
        ->create(['title' => 'Opening times', 'content' => '<p>Open daily.</p>']);
    recordPageRevisionForRm($page);
    $first = resolve(RollbackService::class)->currentVersion($page->uuid);

    $translation->forceFill(['title' => 'Winter opening times'])->save();
    recordPageRevisionForRm($page);
    $second = resolve(RollbackService::class)->currentVersion($page->uuid);

    $comparison = CompareWithPreviousVersionAction::run($page->fresh(), $second);
    $translationChange = collect($comparison->fields)->firstWhere('path', 'translations');

    expect($comparison->previousVersion)->toBe($first)
        ->and(collect($comparison->fields)->pluck('path')->all())->toBe(['translations'])
        ->and(json_encode($translationChange->before))->toContain('Opening times')->not->toContain('Winter opening times')
        ->and(json_encode($translationChange->after))->toContain('Winter opening times')
        ->and(json_encode($translationChange->after))->toContain('Open daily.')
        ->and(CompareWithPreviousVersionAction::run($page->fresh(), $first)->previousVersion)->toBeNull();

    $secondRevision = PageRevision::query()->where('page_uuid', $page->uuid)->where('version', $second)->firstOrFail();

    Livewire::test(EventSourcedHistoryRelationManager::class, [
        'ownerRecord' => $page,
        'pageClass' => EditPage::class,
    ])
        ->assertSuccessful()
        ->assertSee(__('capell-admin::event-sourcing.changed_fields', ['fields' => 'Title']))
        ->mountAction(TestAction::make('compare')->table($secondRevision))
        ->assertMountedActionModalSee(['Opening times', 'Winter opening times', __('capell-admin::event-sourcing.compare_before'), __('capell-admin::event-sourcing.compare_after')]);
});

it('lets editors without restore permission read changes but refuses a forged restore', function (): void {
    $page = Page::factory()->createOne();
    $translation = Translation::factory()->translatable($page)->language(Language::factory()->createOne())
        ->create(['title' => 'Kept', 'content' => '<p>kept</p>']);
    recordPageRevisionForRm($page);
    $target = PageRevision::query()->where('page_uuid', $page->uuid)->orderByDesc('version')->firstOrFail();
    $translation->forceFill(['title' => 'Latest'])->save();
    recordPageRevisionForRm($page);

    test()->actingAsUser();

    $component = Livewire::test(EventSourcedHistoryRelationManager::class, [
        'ownerRecord' => $page,
        'pageClass' => EditPage::class,
    ])
        ->assertActionVisible(TestAction::make('compare')->table($target))
        ->assertActionHidden(TestAction::make('rollback')->table($target));

    // A forged request skips the hidden button, so the server-side check must refuse it on its own.
    $restore = $component->instance()->getTable()->getAction('rollback');

    expect($restore)->not->toBeNull()
        ->and($restore->record($target)->isAuthorized())->toBeFalse();

    expect($translation->fresh()->title)->toBe('Latest')
        ->and(PageRevision::query()->where('page_uuid', $page->uuid)->where('is_rollback', true)->exists())->toBeFalse();
});

it('keeps the timeline loading when a version has no readable stored state', function (): void {
    $page = Page::factory()->withTranslations()->createOne();

    // An index row whose events are missing, as after a partial copy of history.
    $orphan = PageRevision::query()->create([
        'page_uuid' => $page->uuid,
        'version' => 1,
        'summary' => 'Revision recorded',
        'is_rollback' => false,
        'occurred_at' => now(),
    ]);

    Livewire::test(EventSourcedHistoryRelationManager::class, [
        'ownerRecord' => $page,
        'pageClass' => EditPage::class,
    ])
        ->assertSuccessful()
        ->assertSee(__('capell-admin::event-sourcing.change_revision'))
        ->assertActionHidden(TestAction::make('compare')->table($orphan));
});
