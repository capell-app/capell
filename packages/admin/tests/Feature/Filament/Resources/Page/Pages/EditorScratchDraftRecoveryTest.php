<?php

declare(strict_types=1);

use Capell\Admin\Actions\Pages\DiscardPageEditorScratchDraftAction;
use Capell\Admin\Actions\Pages\SavePageEditorScratchDraftAction;
use Capell\Admin\Contracts\Extenders\PageEditExtender;
use Capell\Admin\Data\Pages\PageEditorScratchDraftInputData;
use Capell\Admin\Filament\Livewire\PageScratchDraftPanel;
use Capell\Admin\Filament\Resources\Pages\Pages\EditPage;
use Capell\Admin\Support\Pages\PagePublishSentinel;
use Capell\Core\Actions\EditorScratchDrafts\SaveEditorScratchDraftAction;
use Capell\Core\Models\EditorScratchDraft;
use Capell\Core\Models\Page;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Filament\Actions\Action;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleComponents\Checksum;

uses(CreatesAdminUser::class)
    ->group('page');

beforeEach(function (): void {
    test()->actingAsAdmin();
});

/**
 * @param  array<string, mixed>  $payload
 */
function saveEditorScratchDraft(
    Page $page,
    Authenticatable $user,
    string $locale,
    array $payload,
): EditorScratchDraft {
    return SaveEditorScratchDraftAction::run(
        record: $page,
        user: $user,
        locale: $locale,
        context: 'page-editor',
        payload: $payload,
    );
}

it('mounts with the requested locale and restores that locale draft', function (): void {
    $page = Page::factory()->withTranslations()->createOne();
    $editor = test()->authenticatedUser();

    saveEditorScratchDraft($page, $editor, 'en', ['name' => 'English draft']);
    saveEditorScratchDraft($page, $editor, 'fr', ['name' => 'French draft']);

    Livewire::test(PageScratchDraftPanel::class, [
        'pageId' => $page->getKey(),
        'locale' => 'fr',
    ])
        ->assertSet('locale', 'fr')
        ->assertSee('Restore changes')
        ->call('restore')
        ->assertDispatched(
            'page-scratch-draft-restored',
            pageId: $page->getKey(),
            data: ['name' => 'French draft'],
        );

    Livewire::test(PageScratchDraftPanel::class, [
        'pageId' => $page->getKey(),
    ])->assertSet('locale', app()->getLocale());
});

it('does not read or mutate a draft for an editor without update authorization', function (): void {
    $page = Page::factory()->withTranslations()->createOne();
    $unauthorizedEditor = test()->createUser();

    saveEditorScratchDraft($page, $unauthorizedEditor, app()->getLocale(), ['name' => 'Private draft']);

    test()->actingAs($unauthorizedEditor);

    Livewire::test(PageScratchDraftPanel::class, [
        'pageId' => $page->getKey(),
    ])
        ->assertDontSee('Restore changes')
        ->call('restore')
        ->assertNotDispatched('page-scratch-draft-restored')
        ->call('discard')
        ->assertNotDispatched('page-scratch-draft-updated');

    expect(EditorScratchDraft::query()
        ->forEditor($unauthorizedEditor, $page, app()->getLocale(), 'page-editor')
        ->exists())->toBeTrue();
});

it('discards the current editor draft and notifies the editor', function (): void {
    $page = Page::factory()->withTranslations()->createOne();
    $editor = test()->authenticatedUser();

    saveEditorScratchDraft($page, $editor, app()->getLocale(), ['name' => 'Discard me']);

    Livewire::test(PageScratchDraftPanel::class, [
        'pageId' => $page->getKey(),
    ])
        ->assertSee('Restore changes')
        ->call('discard')
        ->assertNotified(__('capell-admin::scratch_drafts.discarded'))
        ->assertDontSee('Restore changes');

    expect(EditorScratchDraft::query()
        ->forEditor($editor, $page, app()->getLocale(), 'page-editor')
        ->exists())->toBeFalse();
});

it('refreshes the panel when its page draft update event is received', function (): void {
    $page = Page::factory()->withTranslations()->createOne();
    $editor = test()->authenticatedUser();
    $draft = saveEditorScratchDraft($page, $editor, app()->getLocale(), ['name' => 'Refresh me']);

    $panel = Livewire::test(PageScratchDraftPanel::class, [
        'pageId' => $page->getKey(),
    ])->assertSee('Restore changes');

    $draft->delete();

    $panel
        ->dispatch('page-scratch-draft-updated', pageId: $page->getKey())
        ->assertDontSee('Restore changes');
});

it('saves updated edit data and dispatches the panel refresh event', function (): void {
    $page = Page::factory()->withTranslations()->createOne(['name' => 'Saved page']);
    $editor = test()->authenticatedUser();
    $payload = ['name' => 'Recovered page name'];

    $component = Livewire::test(EditPage::class, [
        'record' => $page->getRouteKey(),
    ])->set('data', $payload)
        ->assertDispatched('page-scratch-draft-updated', pageId: $page->getKey());

    $draft = EditorScratchDraft::query()
        ->forEditor($editor, $page, app()->getLocale(), $component->instance()->scratchDraftContext)
        ->sole();

    expect($draft->payload)->toBe($payload)
        ->and($page->refresh()->name)->toBe('Saved page');
});

it('only applies a restore event for the current page to an authorized editor', function (): void {
    $page = Page::factory()->withTranslations()->createOne();
    $otherPage = Page::factory()->withTranslations()->createOne();
    $payload = ['name' => 'Restored page name'];

    $component = Livewire::test(EditPage::class, [
        'record' => $page->getRouteKey(),
    ]);
    $initialData = $component->instance()->data;

    $component
        ->dispatch('page-scratch-draft-restored', pageId: $otherPage->getKey(), data: $payload);

    expect($component->instance()->data)->toBe($initialData);

    $component
        ->dispatch('page-scratch-draft-restored', pageId: $page->getKey(), data: $payload)
        ->assertSet('data', $payload)
        ->assertNotified(__('capell-admin::scratch_drafts.restored'));
});

it('refreshes the panel when a recovery changes after the panel dispatched a restore', function (): void {
    $page = Page::factory()->withTranslations()->createOne();
    $editor = test()->authenticatedUser();
    $draft = saveEditorScratchDraft($page, $editor, app()->getLocale(), ['name' => 'Before']);
    $panel = Livewire::test(PageScratchDraftPanel::class, ['pageId' => $page->getKey()]);
    $component = Livewire::test(EditPage::class, ['record' => $page->getRouteKey()]);
    $initialData = $component->instance()->data;
    $panel->call('restore', $draft->id, $draft->revision, $draft->content_hash)
        ->assertDispatched('page-scratch-draft-restored');
    $oldRevision = $draft->revision;
    $oldHash = $draft->content_hash;
    $newDraft = saveEditorScratchDraft($page, $editor, app()->getLocale(), ['name' => 'After']);

    $component->dispatch('page-scratch-draft-restored', pageId: $page->getKey(), data: ['name' => 'Before'], draftId: $draft->id, revision: $oldRevision, contentHash: $oldHash)
        ->assertSet('data', $initialData)
        ->assertNotified(__('capell-admin::scratch_drafts.changed'))
        ->assertDispatched('page-scratch-draft-updated', pageId: $page->getKey());
    $panel->dispatch('page-scratch-draft-updated', pageId: $page->getKey())->assertSee($newDraft->revision);
});

it('preserves an earlier recovery when the matching page publish event is received', function (): void {
    $page = Page::factory()->withTranslations()->createOne();
    $editor = test()->authenticatedUser();

    saveEditorScratchDraft($page, $editor, app()->getLocale(), ['name' => 'Published page']);

    Livewire::test(EditPage::class, [
        'record' => $page->getRouteKey(),
    ])
        ->dispatch('page-publish-state-changed', pageId: $page->getKey())
        ->assertDispatched('page-scratch-draft-updated', pageId: $page->getKey());

    expect(EditorScratchDraft::query()
        ->forEditor($editor, $page, app()->getLocale(), 'page-editor')
        ->exists())->toBeTrue();
});

it('retains an interrupted recovery draft when the returning editor changes the form', function (): void {
    $page = Page::factory()->withTranslations()->createOne();
    $editor = test()->authenticatedUser();
    $interrupted = ['name' => 'Interrupted changes'];
    saveEditorScratchDraft($page, $editor, app()->getLocale(), $interrupted);

    Livewire::test(EditPage::class, ['record' => $page->getRouteKey()])
        ->set('data.name', 'New unsaved changes');

    Livewire::test(PageScratchDraftPanel::class, ['pageId' => $page->getKey()])
        ->call('restore')
        ->assertDispatched('page-scratch-draft-restored', pageId: $page->getKey(), data: $interrupted);
});

it('rejects stale session writes and deletes even when content changes back', function (): void {
    $page = Page::factory()->withTranslations()->createOne();
    $editor = test()->authenticatedUser();
    $context = 'page-editor:' . Str::uuid();
    $save = static fn (string $name, ?string $revision = null): mixed => SavePageEditorScratchDraftAction::run(new PageEditorScratchDraftInputData($page, $editor, app()->getLocale(), ['name' => $name], $context, $revision));
    $first = $save('A');
    $second = $save('B', $first->revision);
    $third = $save('A', $second->revision);
    $stale = $save('stale', $first->revision);
    $discard = DiscardPageEditorScratchDraftAction::run($page, $editor, app()->getLocale(), $context, $first->revision);

    expect($first->wasSaved())->toBeTrue()
        ->and($second->wasSaved())->toBeTrue()
        ->and($third->wasSaved())->toBeTrue()
        ->and($third->revision)->not->toBe($first->revision)
        ->and($stale->wasSaved())->toBeFalse()
        ->and($discard->affectedRows)->toBe(0)
        ->and(EditorScratchDraft::query()->forEditor($editor, $page, app()->getLocale(), $context)->sole()->payload)->toBe(['name' => 'A']);
});

it('refuses to discard a recovery snapshot that changed after it was displayed', function (): void {
    $page = Page::factory()->withTranslations()->createOne();
    $editor = test()->authenticatedUser();
    $draft = saveEditorScratchDraft($page, $editor, app()->getLocale(), ['name' => 'A']);
    $previousRevision = $draft->revision;
    saveEditorScratchDraft($page, $editor, app()->getLocale(), ['name' => 'B']);
    saveEditorScratchDraft($page, $editor, app()->getLocale(), ['name' => 'A']);

    Livewire::test(PageScratchDraftPanel::class, ['pageId' => $page->getKey()])
        ->call('discard', $draft->id, $previousRevision, $draft->content_hash)
        ->assertNotified(__('capell-admin::scratch_drafts.recovery_changed'))
        ->assertSee('Restore changes');

    expect($draft->fresh())->not->toBeNull()
        ->and($draft->refresh()->revision)->not->toBe($previousRevision);
});

it('refreshes and explains when another editor removed the displayed recovery', function (string $action): void {
    $page = Page::factory()->withTranslations()->createOne();
    $editor = test()->authenticatedUser();
    $draft = saveEditorScratchDraft($page, $editor, app()->getLocale(), ['name' => 'Interrupted']);
    $panel = Livewire::test(PageScratchDraftPanel::class, ['pageId' => $page->getKey()])
        ->assertSee('Restore changes');

    $draft->delete();

    $panel->call($action, $draft->id, $draft->revision, $draft->content_hash)
        ->assertNotDispatched('page-scratch-draft-restored')
        ->assertNotified(__('capell-admin::scratch_drafts.recovery_changed'))
        ->assertDontSee('Restore changes')
        ->assertDontSee('Discard recovery');
})->with(['restore', 'discard']);

it('keeps separate recoveries for two editor sessions and ignores expired snapshots', function (): void {
    $page = Page::factory()->withTranslations()->createOne();
    $editor = test()->authenticatedUser();
    $expired = saveEditorScratchDraft($page, $editor, app()->getLocale(), ['name' => 'Expired']);
    $expired->update(['expires_at' => now()->subDay()]);

    $first = Livewire::test(EditPage::class, ['record' => $page->getRouteKey()])->set('data.name', 'First session');
    $second = Livewire::test(EditPage::class, ['record' => $page->getRouteKey()])->set('data.name', 'Second session');
    expect($first->instance()->scratchDraftContext)->not->toBe($second->instance()->scratchDraftContext)
        ->and(EditorScratchDraft::query()->forEditor($editor, $page, app()->getLocale(), $first->instance()->scratchDraftContext)->sole()->payload['name'])->toBe('First session')
        ->and(EditorScratchDraft::query()->forEditor($editor, $page, app()->getLocale(), $second->instance()->scratchDraftContext)->sole()->payload['name'])->toBe('Second session');
    Livewire::test(PageScratchDraftPanel::class, ['pageId' => $page->getKey(), 'currentContext' => $second->instance()->scratchDraftContext])
        ->call('restore')
        ->assertDispatched('page-scratch-draft-restored', fn ($event, $params): bool => ($params['data']['name'] ?? null) === 'First session');
});

it('initialises a session context when hydrating an editor from an older snapshot', function (): void {
    $page = Page::factory()->withTranslations()->createOne();
    $component = Livewire::test(EditPage::class, ['record' => $page->getRouteKey()]);
    $snapshot = $component->snapshot;
    unset($snapshot['data']['scratchDraftContext'], $snapshot['data']['scratchDraftRevision'], $snapshot['checksum']);
    $snapshot['checksum'] = Checksum::generate($snapshot);
    $component->snapshot = $snapshot;
    $component->set('data.name', 'After upgrade')->assertSuccessful();
    expect($component->instance()->scratchDraftContext)->toStartWith('page-editor:')
        ->and($component->instance()->scratchDraftRevision)->not->toBeNull();
});

it('preserves a newer legacy draft written without a revision token', function (): void {
    $page = Page::factory()->withTranslations()->createOne();
    $editor = test()->authenticatedUser();
    $draft = saveEditorScratchDraft($page, $editor, app()->getLocale(), ['name' => 'A']);
    $draft->update(['revision' => null]);

    $oldHash = $draft->content_hash;
    $draft->update(['payload' => ['name' => 'B'], 'content_hash' => hash('sha256', json_encode(['name' => 'B'], JSON_THROW_ON_ERROR))]);
    Livewire::test(PageScratchDraftPanel::class, ['pageId' => $page->getKey()])->call('discard', $draft->id, null, $oldHash);
    expect($draft->refresh()->payload)->toBe(['name' => 'B']);
});

it('reconciles a lost response only when the exact editor write already committed', function (): void {
    $page = Page::factory()->withTranslations()->createOne();
    $user = test()->authenticatedUser();
    $context = 'page-editor:' . Str::uuid();
    $input = new PageEditorScratchDraftInputData($page, $user, app()->getLocale(), ['name' => 'Saved'], $context);
    $first = SavePageEditorScratchDraftAction::run($input);
    $retry = SavePageEditorScratchDraftAction::run($input);
    $different = SavePageEditorScratchDraftAction::run(new PageEditorScratchDraftInputData($page, $user, app()->getLocale(), ['name' => 'Other'], $context));
    expect($first->wasSaved())->toBeTrue()->and($retry->wasSaved())->toBeTrue()
        ->and($retry->revision)->toBe($first->revision)->and($different->wasSaved())->toBeFalse();
});

it('offers the current editor snapshot after a stale autosave conflict', function (): void {
    $page = Page::factory()->withTranslations()->createOne();
    $component = Livewire::test(EditPage::class, ['record' => $page->getRouteKey()])->set('data.name', 'First');
    $olderSnapshot = $component->snapshot;
    $component->set('data.name', 'Committed');
    $context = $component->instance()->scratchDraftContext;
    $draft = EditorScratchDraft::query()->where('context', $context)->sole();
    $component->snapshot = $olderSnapshot;
    $component->set('data.name', 'New unsaved')->assertDispatched('page-scratch-draft-conflict', pageId: $page->getKey());
    Livewire::test(PageScratchDraftPanel::class, ['pageId' => $page->id, 'currentContext' => $context])
        ->dispatch('page-scratch-draft-conflict', pageId: $page->id)
        ->call('restore')
        ->assertDispatched('page-scratch-draft-restored', fn ($event, $params): bool => ($params['data']['name'] ?? null) === 'Committed');
    expect($draft->refresh()->payload['name'])->toBe('Committed');
    $newContext = $component->instance()->scratchDraftContext;
    expect(EditorScratchDraft::query()->where('context', $newContext)->sole()->payload['name'])->toBe('New unsaved');
    $component->dispatch('page-scratch-draft-restored', pageId: $page->id, data: $draft->payload, draftId: $draft->id, revision: $draft->revision, contentHash: $draft->content_hash)
        ->set('data.name', 'Continued');
    expect($draft->refresh()->payload['name'])->toBe('Committed')
        ->and(EditorScratchDraft::query()->where('context', $newContext)->sole()->payload['name'])->toBe('Continued');
});

it('starts a new editor recovery after its own expired row was pruned', function (): void {
    $page = Page::factory()->withTranslations()->createOne();
    $component = Livewire::test(EditPage::class, ['record' => $page->getRouteKey()])->set('data.name', 'First');
    $oldContext = $component->instance()->scratchDraftContext;
    EditorScratchDraft::query()->where('context', $oldContext)->delete();
    $component->set('data.name', 'After pruning');
    $newContext = $component->instance()->scratchDraftContext;
    expect($newContext)->not->toBe($oldContext)
        ->and(EditorScratchDraft::query()->where('context', $newContext)->sole()->payload['name'])->toBe('After pruning');
});

it('sends the acknowledged form data with the editor saved event', function (): void {
    $page = Page::factory()->withTranslations()->createOne();
    Livewire::test(EditPage::class, ['record' => $page->getRouteKey()])
        ->set('data.name', 'Acknowledged')
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertDispatched('page-editor-saved', pageId: (int) $page->id)
        ->assertDispatched('page-editor-save-acknowledged', fn ($event, $params): bool => $params['pageId'] === $page->id && ($params['data']['name'] ?? null) === 'Acknowledged');
});

it('acknowledges the form when Filament mounts the save-as-draft action', function (): void {
    $page = Page::factory()->withTranslations()->createOne(['visible_from' => PagePublishSentinel::draftValue()]);
    Livewire::test(EditPage::class, ['record' => $page->getRouteKey()])
        ->set('data.name', 'Draft acknowledgement')
        ->call('mountAction', 'saveAsDraft')
        ->assertDispatched('page-editor-save-acknowledged', fn ($event, $params): bool => ($params['data']['name'] ?? null) === 'Draft acknowledgement');
});

it('acknowledges the draft only when a mounted confirmation action executes', function (): void {
    $extender = new class implements PageEditExtender
    {
        public function getFormActions(): array
        {
            return [Action::make('saveAsDraft')->requiresConfirmation()->action(function (EditPage $livewire): void {
                $livewire->saveAsDraft();
            })];
        }

        public function getHeaderWidgets(): array
        {
            return [];
        }
    };
    app()->instance('test-confirmed-draft-extender', $extender);
    app()->tag('test-confirmed-draft-extender', PageEditExtender::TAG);

    $page = Page::factory()->withTranslations()->createOne(['visible_from' => PagePublishSentinel::draftValue()]);
    Livewire::test(EditPage::class, ['record' => $page->getRouteKey()])
        ->set('data.name', 'Confirmed draft')
        ->call('mountAction', 'saveAsDraft')
        ->assertNotDispatched('page-editor-save-acknowledged')
        ->call('unmountAction')
        ->assertNotDispatched('page-editor-save-acknowledged')
        ->call('mountAction', 'saveAsDraft')
        ->call('callMountedAction')
        ->assertDispatched('page-editor-save-acknowledged', fn ($event, $params): bool => ($params['data']['name'] ?? null) === 'Confirmed draft');
});
