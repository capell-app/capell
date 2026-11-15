<?php

declare(strict_types=1);

use Capell\Core\Actions\Ai\RecordAiProvenanceAction;
use Capell\Core\Data\Ai\AiProvenanceRecordData;
use Capell\Core\Enums\AiDisclosure;
use Capell\Core\Models\Page;

it('keeps the strongest AI disclosure of a page on its meta', function (): void {
    $page = Page::factory()->create(['meta' => ['show_hero' => true]]);

    RecordAiProvenanceAction::run(new AiProvenanceRecordData($page, 'title', AiDisclosure::Assisted));
    expect(Page::query()->whereKey($page->getKey())->sole()->meta)->toMatchArray(['ai_disclosure' => 'assisted', 'show_hero' => true]);

    RecordAiProvenanceAction::run(new AiProvenanceRecordData($page, 'content', AiDisclosure::Generated));
    RecordAiProvenanceAction::run(new AiProvenanceRecordData($page, 'title', AiDisclosure::None));

    expect(Page::query()->whereKey($page->getKey())->sole()->meta['ai_disclosure'] ?? null)->toBe('generated');
});
