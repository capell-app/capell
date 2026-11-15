<?php

declare(strict_types=1);

use Capell\Admin\Filament\Resources\Pages\PageResource;
use Capell\Admin\Support\AdminGlossary;
use Capell\Core\Models\Page;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Dom\Element;
use Dom\HTMLDocument;

uses(CreatesAdminUser::class)->group('admin');

function adminGlossaryDocument(string $html): HTMLDocument
{
    return HTMLDocument::createFromString($html, LIBXML_NOERROR);
}

/** @return list<string> */
function adminGlossaryTexts(Element|HTMLDocument $root, string $selector): array
{
    $texts = [];
    foreach ($root->querySelectorAll($selector) as $node) {
        $texts[] = trim((string) $node->textContent);
    }

    return $texts;
}

it('defines every plain word with the technical word it replaces and what it means', function (): void {
    $terms = AdminGlossary::terms();

    expect($terms)->not->toBeEmpty();

    foreach ($terms as $word) {
        expect($word['term'])->not->toBeEmpty()
            ->and($word['instead_of'])->not->toBeEmpty()->not->toBe($word['term'])
            ->and($word['meaning'])->not->toBeEmpty();
    }

    expect(array_unique(array_column($terms, 'term')))->toHaveCount(count($terms));
});

it('keeps the replaced technical words out of the page editor form', function (): void {
    test()->actingAsAdmin();
    $page = Page::factory()->withTranslations()->createOne();

    $html = (string) $this->get(PageResource::getUrl('edit', ['record' => $page]))->assertOk()->getContent();
    $document = adminGlossaryDocument($html);
    expect($document->querySelectorAll('form')->length)->toBeGreaterThan(0);

    $shown = adminGlossaryTexts($document, 'form');
    foreach ($document->querySelectorAll('form [aria-label], form [title], form [placeholder]') as $node) {
        $shown[] = implode(' ', array_filter([
            $node->getAttribute('aria-label'), $node->getAttribute('title'), $node->getAttribute('placeholder'),
        ]));
    }

    $visibleText = implode(' ', $shown);

    foreach (AdminGlossary::terms() as $word) {
        expect(preg_match('/\b' . preg_quote($word['instead_of'], '/') . '\b/u', $visibleText))
            ->toBe(0, sprintf('The page editor still shows "%s"; use "%s".', $word['instead_of'], $word['term']));
    }
});
