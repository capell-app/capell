<?php

declare(strict_types=1);

use Capell\Admin\Filament\Livewire\PageScratchDraftPanel;
use Capell\Admin\Filament\Resources\Pages\Pages\EditPage;
use Capell\Core\Actions\EditorScratchDrafts\SaveEditorScratchDraftAction;
use Capell\Core\Models\EditorScratchDraft;
use Capell\Core\Models\Page;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Livewire\Livewire;

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

    Livewire::test(EditPage::class, [
        'record' => $page->getRouteKey(),
    ])
        ->set('data', $payload)
        ->assertDispatched('page-scratch-draft-updated', pageId: $page->getKey());

    $draft = EditorScratchDraft::query()
        ->forEditor($editor, $page, app()->getLocale(), 'page-editor')
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

it('discards the page draft when the matching page publish event is received', function (): void {
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
        ->exists())->toBeFalse();
});
