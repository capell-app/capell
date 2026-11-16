<?php

declare(strict_types=1);

use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Frontend\Data\PageListingRequestData;
use Capell\Frontend\Support\Loader\PageLoader;
use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;

it('loads children and respects optionalLanguage flag', function (): void {
    $site = Site::factory()->withTranslations()->create();
    Blueprint::factory()->page()->create();

    /** @var Language $language */
    $language = $site->language;

    $parent = Page::factory()->site($site)->home()->withTranslations(slug: '/')->create();
    $child1 = Page::factory()->site($site)->parent($parent)->withTranslations()->create();
    $child2 = Page::factory()->site($site)->parent($parent)->withTranslations()->create();

    $children = PageLoader::list(new PageListingRequestData(
        language: $language,
        optionalLanguage: true,
        morphModel: Page::class,
        useCache: false,
        modifyQuery: fn (BuilderContract $query): BuilderContract => $query->where('parent_id', $parent->id),
    ));

    expect($children)->toBeIterable()
        ->toHaveCount(2)
        ->and($children->pluck('id')->toArray())->toContain($child1->id, $child2->id);
});

it('keeps canonical pages apart for different pageable types that share an id', function (): void {
    config()->set('cache.default', 'array');

    $english = Language::factory()->english()->create();
    $site = Site::factory()->language($english)->withTranslations($english)->create();
    $target = Page::factory()->site($site)->published()->withTranslations($english, slug: '/canonical-target')->create();

    // Another pageable type numbers its records independently, so the same id names a
    // different record with its own canonical pages.
    $article = new class extends Page
    {
        public function getMorphClass(): string
        {
            return 'test-article';
        }
    };
    $article->setRawAttributes($target->getAttributes(), true);
    $article->exists = true;

    $pageCanonical = Page::factory()->site($site)->published()->withTranslations($english, slug: '/points-at-page')->create([
        'meta' => ['canonical_pageable_type' => $target->getMorphClass(), 'canonical_pageable_id' => $target->getKey()],
    ]);
    $articleCanonical = Page::factory()->site($site)->published()->withTranslations($english, slug: '/points-at-article')->create([
        'meta' => ['canonical_pageable_type' => 'test-article', 'canonical_pageable_id' => $target->getKey()],
    ]);

    $forPage = PageLoader::getCanonicalPages($target, $english)->modelKeys();
    $forArticle = PageLoader::getCanonicalPages($article, $english)->modelKeys();

    expect($article->getKey())->toBe($target->getKey())
        ->and($forPage)->toBe([$pageCanonical->getKey()])
        ->and($forArticle)->toBe([$articleCanonical->getKey()]);
});
