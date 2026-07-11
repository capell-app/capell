<?php

declare(strict_types=1);

use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
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

    $children = PageLoader::getPages(
        language: $language,
        optionalLanguage: true,
        morphModel: Page::class,
        useCache: false,
        modifyQuery: fn (BuilderContract $query): BuilderContract => $query->where('parent_id', $parent->id),
    );

    expect($children)->toBeIterable()
        ->toHaveCount(2)
        ->and($children->pluck('id')->toArray())->toContain($child1->id, $child2->id);
});
