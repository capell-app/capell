<?php

declare(strict_types=1);

use Capell\Core\Actions\SetupPageUrlsAction;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;

beforeEach(function (): void {
    $this->language = Language::factory()->createOne();
    $this->site = Site::factory()->createOne();

    $this->parent = Page::factory()->createOne([
        'site_id' => $this->site->id,
        'name' => 'Parent',
    ]);
    Translation::factory()
        ->translatable($this->parent)
        ->language($this->language)
        ->slug('/parent')
        ->create();

    $this->child = Page::factory()->createOne([
        'site_id' => $this->site->id,
        'parent_id' => $this->parent->id,
        'name' => 'Child',
    ]);
    Translation::factory()
        ->translatable($this->child)
        ->language($this->language)
        ->slug('/child')
        ->create();
});

function pageUrlFor(Page $page, Language $language): ?PageUrl
{
    return PageUrl::query()
        ->where('pageable_type', $page->getMorphClass())
        ->where('pageable_id', $page->id)
        ->where('language_id', $language->id)
        ->first();
}

it('creates the page url from the resolved parent url for each translation', function (): void {
    SetupPageUrlsAction::run($this->parent, updateDescendants: false);

    $parentUrl = pageUrlFor($this->parent, $this->language);

    expect($parentUrl)->not->toBeNull()
        ->and($parentUrl?->url)->toBe('/parent');
});

it('refreshes an already loaded translation before updating its url', function (): void {
    $this->parent->load('translations.language');
    $translation = Translation::query()
        ->where('translatable_type', $this->parent->getMorphClass())
        ->where('translatable_id', $this->parent->getKey())
        ->firstOrFail();
    $translation->forceFill([
        'meta' => array_merge($translation->meta ?? [], ['slug' => 'renamed-parent']),
    ])->save();

    SetupPageUrlsAction::run($this->parent, updateDescendants: false);

    expect(pageUrlFor($this->parent, $this->language)?->url)->toBe('/renamed-parent');
});

it('cascades url updates to descendants when requested', function (): void {
    SetupPageUrlsAction::run($this->parent, updateDescendants: true);

    $childUrl = pageUrlFor($this->child, $this->language);

    expect($childUrl)->not->toBeNull()
        ->and($childUrl?->url)->toBe('/parent/child');
});

it('does not recreate descendant urls when descendant updates are disabled', function (): void {
    // Clear any url the observer created for the descendant so we can assert
    // the action leaves descendants untouched when cascading is disabled.
    PageUrl::query()
        ->where('pageable_type', $this->child->getMorphClass())
        ->where('pageable_id', $this->child->id)
        ->forceDelete();

    expect(pageUrlFor($this->child, $this->language))->toBeNull();

    SetupPageUrlsAction::run($this->parent, updateDescendants: false);

    expect(pageUrlFor($this->child, $this->language))->toBeNull();
});
