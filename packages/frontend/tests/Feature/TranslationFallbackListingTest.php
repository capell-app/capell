<?php

declare(strict_types=1);

use Capell\Core\Enums\PageOrderEnum;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Frontend\Data\PageListingRequestData;
use Capell\Frontend\Support\Loader\PageLoader;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * @return array{Site, Language, Language, Blueprint}
 */
function listingFallbackSite(bool $frenchFallsBackToEnglish = true): array
{
    $english = Language::factory()->english()->createOne();
    $french = Language::factory()->french()->createOne([
        'fallback_language_id' => $frenchFallsBackToEnglish ? $english->getKey() : null,
    ]);
    $site = Site::factory()->language($english)->withTranslations([$english, $french])->createOne();

    return [$site, $english, $french, Blueprint::factory()->page()->meta(['with_next_prev' => true])->createOne()];
}

/**
 * @param  array<int, array{0: Language, 1: string, 2: string}>  $translations  language, slug, title
 */
function listingFallbackPage(Site $site, Blueprint $type, array $translations, int $order, ?Page $parent = null): Page
{
    $languages = array_map(static fn (array $translation): Language => $translation[0], $translations);
    $factory = Page::factory()->site($site)->type($type)->published(CarbonImmutable::parse('2026-01-01 09:00:00'))
        ->state(['order' => $order])
        ->withTranslations($languages);

    if ($parent instanceof Page) {
        $factory = $factory->parent($parent);
    }

    $page = $factory->createOne();

    foreach ($translations as [$language, $slug, $title]) {
        $translation = $page->translations()->where('language_id', $language->getKey())->sole();
        $meta = is_array($translation->meta) ? $translation->meta : [];
        $translation->update(['title' => $title, 'meta' => array_replace($meta, ['slug' => $slug])]);
    }

    return $page;
}

/**
 * @return list<string>
 */
function listingFallbackTitles(iterable $pages): array
{
    $titles = [];

    foreach ($pages as $page) {
        $titles[] = (string) $page->translation?->title;
    }

    return $titles;
}

it('lists pages the language serves from its fallback beside its own translations', function (): void {
    [$site, $english, $french, $type] = listingFallbackSite();
    listingFallbackPage($site, $type, [[$english, 'alpha', 'Alpha EN']], order: 1);
    listingFallbackPage($site, $type, [[$english, 'bravo', 'Bravo EN'], [$french, 'bravo-fr', 'Bravo FR']], order: 2);
    listingFallbackPage($site, $type, [[$english, 'charlie', 'Charlie EN']], order: 3);

    $french = PageLoader::list(PageListingRequestData::fromLegacy(language: $french, site: $site, ordering: PageOrderEnum::Default));
    $english = PageLoader::list(PageListingRequestData::fromLegacy(language: $english, site: $site, ordering: PageOrderEnum::Default));

    expect(listingFallbackTitles($french))->toBe(['Alpha EN', 'Bravo FR', 'Charlie EN'])
        ->and(listingFallbackTitles($english))->toBe(['Alpha EN', 'Bravo EN', 'Charlie EN']);
});

it('keeps untranslated pages out of a listing when the language has no fallback', function (): void {
    [$site, $english, $french, $type] = listingFallbackSite(frenchFallsBackToEnglish: false);
    listingFallbackPage($site, $type, [[$english, 'alpha', 'Alpha EN']], order: 1);
    listingFallbackPage($site, $type, [[$english, 'bravo', 'Bravo EN'], [$french, 'bravo-fr', 'Bravo FR']], order: 2);

    $listed = PageLoader::list(PageListingRequestData::fromLegacy(language: $french, site: $site, ordering: PageOrderEnum::Default));

    expect(listingFallbackTitles($listed))->toBe(['Bravo FR']);
});

it('labels fallback-served listing items with the language their content is written in', function (): void {
    [$site, $english, $french, $type] = listingFallbackSite();
    listingFallbackPage($site, $type, [[$english, 'alpha', 'Alpha EN']], order: 1);

    $listed = PageLoader::list(PageListingRequestData::fromLegacy(language: $french, site: $site, ordering: PageOrderEnum::Default));
    $item = $listed->sole();

    expect($item->translation?->language?->is($english))->toBeTrue()
        ->and($item->pageUrl?->language?->is($english))->toBeTrue();
});

it('sorts an alphabetical listing by the served title, fallback or own', function (): void {
    [$site, $english, $french, $type] = listingFallbackSite();
    listingFallbackPage($site, $type, [[$english, 'zulu', 'Zulu EN']], order: 1);
    listingFallbackPage($site, $type, [[$english, 'mike', 'Mike EN'], [$french, 'mike-fr', 'Alpha FR']], order: 2);
    listingFallbackPage($site, $type, [[$english, 'bravo', 'Bravo EN']], order: 3);

    $listed = PageLoader::list(PageListingRequestData::fromLegacy(
        language: $french,
        site: $site,
        ordering: PageOrderEnum::Alphabetical,
    ));

    expect(listingFallbackTitles($listed))->toBe(['Alpha FR', 'Bravo EN', 'Zulu EN']);
});

it('serves a fallback listing with exactly the queries an own-language listing costs', function (): void {
    [$site, $english, $french, $type] = listingFallbackSite();

    foreach (range(1, 6) as $order) {
        listingFallbackPage($site, $type, [[$english, 'page-' . $order, 'Page ' . $order . ' EN']], order: $order);
    }

    $count = static function (Language $language) use ($site): int {
        DB::enableQueryLog();
        DB::flushQueryLog();
        PageLoader::list(PageListingRequestData::fromLegacy(language: $language, site: $site, ordering: PageOrderEnum::Default, useCache: false));
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    // The same six pages, listed in their own language and through the fallback: serving
    // from the fallback must not add a query anywhere in the listing.
    expect($count($french))->toBe($count($english));
});

it('sorts by the first language of the chain that has a title', function (): void {
    [$site, $english, $french, $type] = listingFallbackSite();
    $german = Language::factory()->german()->createOne(['fallback_language_id' => $english->getKey()]);
    $french->update(['fallback_language_id' => $german->getKey()]);
    SiteDomain::factory()->site($site)->language($german)->createOne(['path' => '/de']);
    // The English title sorts first, the German one last: the chain french -> german -> english
    // must sort this page by its German title.
    listingFallbackPage($site, $type, [[$english, 'aaa', 'Aaa EN'], [$german, 'zzz', 'Zzz DE']], order: 1);
    listingFallbackPage($site, $type, [[$english, 'mmm', 'Mmm EN']], order: 2);

    $listed = PageLoader::list(PageListingRequestData::fromLegacy(language: $french, site: $site, ordering: PageOrderEnum::Alphabetical));

    expect(listingFallbackTitles($listed))->toBe(['Mmm EN', 'Zzz DE']);
});

it('keeps fallback-served ancestors in the breadcrumb trail', function (): void {
    [$site, $english, $french, $type] = listingFallbackSite();
    $parent = listingFallbackPage($site, $type, [[$english, 'section', 'Section EN']], order: 1);
    $child = listingFallbackPage($site, $type, [[$english, 'section/child', 'Child EN'], [$french, 'section-fr/enfant', 'Enfant FR']], order: 1, parent: $parent);

    $ancestors = PageLoader::getPageAncestors(page: $child, language: $french, site: $site);

    expect($ancestors?->pluck('id')->all())->toBe([$parent->getKey()])
        ->and($ancestors?->first()?->translation?->title)->toBe('Section EN');
});

it('offers a fallback-served neighbour for next and previous links', function (): void {
    [$site, $english, $french, $type] = listingFallbackSite();
    $first = listingFallbackPage($site, $type, [[$english, 'first', 'First EN'], [$french, 'premier', 'Premier FR']], order: 1);
    $second = listingFallbackPage($site, $type, [[$english, 'second', 'Second EN']], order: 2);

    $next = PageLoader::getNextPage($first, $site, $french);
    $previous = PageLoader::getPreviousPage($second, $site, $french);

    expect($next?->getKey())->toBe($second->getKey())
        ->and($next?->translation?->title)->toBe('Second EN')
        ->and($previous?->getKey())->toBe($first->getKey())
        ->and($previous?->translation?->title)->toBe('Premier FR');
});

it('keeps the site home page available in a language that serves it from the fallback', function (): void {
    [$site, $english, $french] = listingFallbackSite();
    $home = Page::factory()->site($site)->home()->published()->withTranslations([$english])->createOne();

    $served = PageLoader::getSiteHomePage($site, $french);

    expect($served?->getKey())->toBe($home->getKey())
        ->and($served?->translation?->language_id)->toBe($english->getKey());
});

it('prefers the home page translated into the language over one served by fallback', function (bool $ownCreatedFirst): void {
    [$site, $english, $french] = listingFallbackSite();
    $makeFallbackOnly = fn (): Page => Page::factory()->site($site)->home()->published()->withTranslations([$english], [], slug: '/home-en')->createOne();
    $makeOwn = fn (): Page => Page::factory()->site($site)->home()->published()->withTranslations([$french], [], slug: '/accueil')->createOne();

    if ($ownCreatedFirst) {
        $own = $makeOwn();
        $makeFallbackOnly();
    } else {
        $makeFallbackOnly();
        $own = $makeOwn();
    }

    expect(PageLoader::getSiteHomePage($site, $french)?->getKey())->toBe($own->getKey());
})->with([
    'own home created first' => [true],
    'own home created last' => [false],
]);
