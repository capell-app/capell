<?php

declare(strict_types=1);

use Capell\Admin\Actions\Pages\BuildPageDeletionImpactAction;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;

it('reports the authoritative direct page URL impact with a safe pageable URL filter', function (): void {
    $page = Page::factory()->createOne();
    PageUrl::factory()->page($page)->site($page->site)->language($page->site->language)->count(2)->create();

    $impact = BuildPageDeletionImpactAction::run($page->loadCount('pageUrls'));

    expect($impact->knownReferenceCount)->toBe(2)
        ->and($impact->authoritative)->toBeTrue()
        ->and($impact->affectedLabel)->toBe('2 known page URLs')
        ->and(urldecode((string) $impact->referencesUrl))
        ->toContain('filters[pageable][pageable_type]=' . $page->getMorphClass())
        ->toContain('filters[pageable][pageable_id]=' . $page->getKey());
});

it('does not claim indirect page references or provide a URL filter without direct URLs', function (): void {
    $page = Page::factory()->createOne();
    $page->pageUrls()->delete();

    $impact = BuildPageDeletionImpactAction::run($page);

    expect($impact->knownReferenceCount)->toBe(0)
        ->and($impact->authoritative)->toBeTrue()
        ->and($impact->referencesUrl)->toBeNull();
});
