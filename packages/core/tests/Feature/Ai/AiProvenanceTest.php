<?php

declare(strict_types=1);

use Capell\Core\Actions\Ai\ConfirmAiProvenanceAction;
use Capell\Core\Actions\Ai\RecordAiProvenanceAction;
use Capell\Core\Data\Ai\AiProvenanceRecordData;
use Capell\Core\Enums\AiDisclosure;
use Capell\Core\EventSourcing\Rollback\Actions\ApplyRollbackAction;
use Capell\Core\EventSourcing\Rollback\RollbackService;
use Capell\Core\Models\AiProvenance;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Translation;

function aiProvenancePage(): array
{
    $language = Language::factory()->create();
    $page = Page::factory()->create();
    Translation::factory()->translatable($page)->language($language)
        ->create(['title' => 'Title', 'content' => '<p>content</p>']);

    return [$page, $language];
}

function recordAiField(Page $page, Language $language, string $field, AiDisclosure $disclosure = AiDisclosure::Generated): AiProvenance
{
    return RecordAiProvenanceAction::run(new AiProvenanceRecordData(
        pageable: $page,
        field: $field,
        disclosure: $disclosure,
        languageId: $language->id,
        capability: 'seo.meta-description',
        provider: 'anthropic',
        model: 'claude-sonnet-5-5',
        actorId: 7,
    ));
}

it('records one row per page, language and field and clears the review when the AI writes again', function (): void {
    [$page, $language] = aiProvenancePage();

    recordAiField($page, $language, 'meta_description');
    ConfirmAiProvenanceAction::run($page, reviewerId: 3);

    expect(AiProvenance::query()->sole()->isConfirmed())->toBeTrue();

    recordAiField($page, $language, 'meta_description', AiDisclosure::Assisted);

    $row = AiProvenance::query()->sole();

    expect($row->disclosure)->toBe(AiDisclosure::Assisted)
        ->and($row->isConfirmed())->toBeFalse()
        ->and($row->confirmed_by)->toBeNull();
});

it('confirms only the named fields and records who reviewed them', function (): void {
    [$page, $language] = aiProvenancePage();
    recordAiField($page, $language, 'title');
    recordAiField($page, $language, 'content');

    $confirmed = ConfirmAiProvenanceAction::run($page, reviewerId: 5, fields: ['title']);

    expect($confirmed)->toBe(1)
        ->and(AiProvenance::query()->where('field', 'title')->sole()->confirmed_by)->toBe(5)
        ->and(AiProvenance::query()->where('field', 'content')->sole()->isConfirmed())->toBeFalse();
});

it('restores AI provenance and its review state when the page is rolled back', function (): void {
    [$page, $language] = aiProvenancePage();
    recordAiField($page, $language, 'title');
    $page->fresh()->save();
    $targetVersion = resolve(RollbackService::class)->currentVersion($page->uuid);

    recordAiField($page, $language, 'content');
    ConfirmAiProvenanceAction::run($page, reviewerId: 9);
    $page->fresh()->save();

    ApplyRollbackAction::run($page->fresh(), $targetVersion);

    $row = AiProvenance::query()->sole();

    expect($row->field)->toBe('title')
        ->and($row->isConfirmed())->toBeFalse()
        ->and($row->provider)->toBe('anthropic');
});
