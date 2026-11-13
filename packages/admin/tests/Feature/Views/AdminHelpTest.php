<?php

declare(strict_types=1);

use Capell\Admin\Actions\ResolveAdminHelpAction;
use Capell\Admin\Data\AdminHelpAnswerData;
use Capell\Admin\Data\AdminHelpEntryData;
use Capell\Admin\Filament\Resources\Pages\PageResource;
use Capell\Admin\Support\AdminGlossary;
use Capell\Admin\Support\AdminHelpRegistry;
use Capell\Admin\Support\CoreAdminHelpEntries;
use Capell\Core\Models\Page;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Dom\Element;
use Dom\HTMLDocument;
use Illuminate\Support\Facades\Route;

uses(CreatesAdminUser::class)->group('admin');

function adminHelpDocument(string $html): HTMLDocument
{
    return HTMLDocument::createFromString($html, LIBXML_NOERROR);
}

/** @return list<string> */
function adminHelpTexts(Element|HTMLDocument $root, string $selector): array
{
    $texts = [];
    foreach ($root->querySelectorAll($selector) as $node) {
        $texts[] = trim((string) $node->textContent);
    }

    return $texts;
}

/** @return array<string, string> glossary term => meaning */
function adminHelpGlossary(): array
{
    $words = [];
    foreach (AdminGlossary::terms() as $word) {
        expect($word['term'])->not->toBeEmpty()
            ->and($word['instead_of'])->not->toBeEmpty()->not->toBe($word['term'])
            ->and($word['meaning'])->not->toBeEmpty();
        $words[$word['term']] = $word['meaning'];
    }

    expect($words)->not->toBeEmpty();

    return $words;
}

it('provides help for every built-in screen route', function (): void {
    $registry = resolve(AdminHelpRegistry::class);

    foreach (array_keys(CoreAdminHelpEntries::entries()) as $route) {
        if ($route !== 'filament.admin.pages.recently-deleted') {
            expect(Route::has($route))->toBeTrue('Missing route: ' . $route);
        }

        $entry = $registry->get($route);
        expect($entry)->toBeInstanceOf(AdminHelpEntryData::class);
        assert($entry instanceof AdminHelpEntryData);
        expect($entry->title)->not->toBeEmpty()
            ->and($entry->summary)->not->toBeEmpty()
            ->and($entry->nextAction)->not->toBeEmpty();
    }

    expect($registry->get('unknown.route'))->toBeNull();
});

it('answers editor tasks on the pages, page editor, media, users and settings screens', function (string $route): void {
    $entry = resolve(AdminHelpRegistry::class)->get($route);
    assert($entry instanceof AdminHelpEntryData);

    expect($entry->answers)->toHaveCount(4);

    $questions = [];
    foreach ($entry->answers as $answer) {
        expect($answer->question)->toEndWith('?')
            ->and($answer->answer)->not->toBeEmpty();
        $questions[] = $answer->question;
    }

    expect(array_unique($questions))->toHaveCount(count($questions));
})->with([
    'pages' => 'filament.admin.resources.pages.index',
    'page editor' => 'filament.admin.resources.pages.edit',
    'media' => 'filament.admin.resources.media.index',
    'users' => 'filament.admin.resources.users.index',
    'settings' => 'filament.admin.pages.settings',
]);

it('shows the screen answers and plain-words glossary in the help panel', function (callable $url, string $route): void {
    test()->actingAsAdmin();

    $response = $this->get($url());
    $response->assertOk();

    $entry = resolve(AdminHelpRegistry::class)->get($route);
    assert($entry instanceof AdminHelpEntryData);

    $panels = adminHelpDocument((string) $response->getContent())->querySelectorAll('details[data-admin-help]');
    expect($panels->length)->toBe(1);
    $panel = $panels->item(0);
    assert($panel instanceof Element);
    expect($panel->firstElementChild?->tagName)->toBe('SUMMARY')
        ->and(trim((string) $panel->firstElementChild?->textContent))->not->toBeEmpty();

    $answers = $panel->querySelectorAll('[data-admin-help-answer]');
    expect($answers->length)->toBe(count($entry->answers));
    foreach ($entry->answers as $index => $answer) {
        $node = $answers->item($index);
        assert($node instanceof Element);
        expect(adminHelpTexts($node, 'summary'))->toBe([$answer->question])
            ->and(adminHelpTexts($node, 'p'))->toBe([$answer->answer]);
    }

    $terms = adminHelpTexts($panel, '[data-admin-help-glossary] dt');
    $meanings = adminHelpTexts($panel, '[data-admin-help-glossary] dd');
    foreach (adminHelpGlossary() as $term => $meaning) {
        expect($terms)->toContain($term)
            ->and($meanings)->toContain($meaning);
    }
})->with([
    'pages' => [fn (): string => route('filament.admin.resources.pages.index'), 'filament.admin.resources.pages.index'],
    'page editor' => [fn (): string => PageResource::getUrl('edit', ['record' => Page::factory()->withTranslations()->createOne()]), 'filament.admin.resources.pages.edit'],
    'media' => [fn (): string => route('filament.admin.resources.media.index'), 'filament.admin.resources.media.index'],
    'users' => [fn (): string => route('filament.admin.resources.users.index'), 'filament.admin.resources.users.index'],
    'settings' => [fn (): string => route('filament.admin.pages.settings'), 'filament.admin.pages.settings'],
]);

it('keeps the replaced technical words out of the page editor form', function (): void {
    test()->actingAsAdmin();
    $page = Page::factory()->withTranslations()->createOne();

    $html = (string) $this->get(PageResource::getUrl('edit', ['record' => $page]))->assertOk()->getContent();
    $document = adminHelpDocument($html);
    expect($document->querySelectorAll('form')->length)->toBeGreaterThan(0);

    $shown = adminHelpTexts($document, 'form');
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

it('lets packages add their own screens and extra answers to existing screens', function (): void {
    app()->afterResolving(AdminHelpRegistry::class, function (AdminHelpRegistry $registry): void {
        $registry->register('filament.admin.resources.package-screen.index', new AdminHelpEntryData(
            title: 'Package screen',
            summary: 'Summary',
            nextAction: 'Next',
            answers: [new AdminHelpAnswerData('How do I use it?', 'Like this.')],
        ));
        $registry->addAnswers('filament.admin.resources.pages.index', new AdminHelpAnswerData('How do I add a page to the menu?', 'Open Menus.'));
    });
    app()->forgetScopedInstances();

    $registry = resolve(AdminHelpRegistry::class);
    $pages = $registry->get('filament.admin.resources.pages.index');
    assert($pages instanceof AdminHelpEntryData);

    expect($registry->get('filament.admin.resources.package-screen.index')?->answers)->toHaveCount(1)
        ->and($pages->answers)->toHaveCount(5)
        ->and($pages->answers[4]->question)->toBe('How do I add a page to the menu?')
        ->and($registry->addAnswers('unknown.route', new AdminHelpAnswerData('Q?', 'A.')))->toBeFalse()
        ->and($registry->get('unknown.route'))->toBeNull();
});

it('resolves no help outside a registered route', function (): void {
    expect(ResolveAdminHelpAction::run())->toBeNull();
});
