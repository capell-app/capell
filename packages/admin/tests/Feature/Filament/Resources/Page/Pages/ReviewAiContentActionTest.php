<?php

declare(strict_types=1);

use Capell\Admin\Filament\Resources\Pages\Pages\EditPage;
use Capell\Core\Actions\Ai\RecordAiProvenanceAction;
use Capell\Core\Data\Ai\AiProvenanceRecordData;
use Capell\Core\Enums\AiDisclosure;
use Capell\Core\Models\AiProvenance;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Translation;
use Livewire\Livewire;

function aiReviewPage(): Page
{
    $language = Language::factory()->createOne();
    $page = Page::factory()->recycle($language)->create();
    Translation::factory()->language($language)->translatable($page)->create();

    return $page;
}

it('offers the review only while the page has unreviewed AI-written fields', function (): void {
    test()->actingAsAdmin();
    $page = aiReviewPage();

    Livewire::test(EditPage::class, ['record' => $page->getRouteKey()])
        ->assertActionHidden('review-ai-content');

    RecordAiProvenanceAction::run(new AiProvenanceRecordData($page, 'meta_description', AiDisclosure::Generated, capability: 'seo.meta-description'));

    Livewire::test(EditPage::class, ['record' => $page->getRouteKey()])
        ->assertActionVisible('review-ai-content')
        ->assertActionHasLabel('review-ai-content', __('capell-admin::ai.review_action', ['count' => 1]));
});

it('records the signed-in editor as reviewer and stops offering the review', function (): void {
    test()->actingAsAdmin();
    $page = aiReviewPage();
    RecordAiProvenanceAction::run(new AiProvenanceRecordData($page, 'content', AiDisclosure::Assisted));

    Livewire::test(EditPage::class, ['record' => $page->getRouteKey()])
        ->callAction('review-ai-content')
        ->assertNotified(__('capell-admin::ai.review_done'));

    $row = AiProvenance::query()->sole();

    expect($row->isConfirmed())->toBeTrue()
        ->and($row->confirmed_by)->toBe(test()->authenticatedUser()->getKey());

    Livewire::test(EditPage::class, ['record' => $page->getRouteKey()])
        ->assertActionHidden('review-ai-content');
});
