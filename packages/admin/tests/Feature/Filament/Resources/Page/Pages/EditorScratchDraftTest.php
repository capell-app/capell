<?php

declare(strict_types=1);

use Capell\Admin\Filament\Livewire\PageScratchDraftPanel;
use Capell\Admin\Filament\Resources\Pages\Pages\EditPage;
use Capell\Core\Actions\EditorScratchDrafts\SaveEditorScratchDraftAction;
use Capell\Core\Models\EditorScratchDraft;
use Capell\Core\Models\Page;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Livewire\Livewire;

uses(CreatesAdminUser::class)
    ->group('page');

beforeEach(function (): void {
    test()->actingAsAdmin();
});

it('shows and controls only the current editor recovery draft', function (): void {
    $page = Page::factory()->withTranslations()->createOne();
    $editor = auth()->user();

    SaveEditorScratchDraftAction::run(
        record: $page,
        user: $editor,
        locale: app()->getLocale(),
        context: 'page-editor',
        payload: ['name' => 'Recovered name'],
    );

    Livewire::test(PageScratchDraftPanel::class, ['pageId' => $page->getKey()])
        ->assertSuccessful()
        ->assertSee('Recovery draft')
        ->assertSee('Restore changes')
        ->call('discard')
        ->assertDontSee('Restore changes');

    expect(EditorScratchDraft::query()->count())->toBe(0);
});

it('keeps the editor form actions sticky', function (): void {
    expect(EditPage::$formActionsAreSticky)->toBeTrue();
});
