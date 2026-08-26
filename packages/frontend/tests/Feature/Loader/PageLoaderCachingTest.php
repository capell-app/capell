<?php

declare(strict_types=1);

use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Frontend\Data\PageListingRequestData;
use Capell\Frontend\Support\Loader\PageLoader;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Build the test scaffolding for caching behaviour: one site, one language,
 * one listable page type, and a handful of published pages.
 *
 * @return array{language: Language, site: Site, type: Blueprint, pages: list<Page>}
 */
function makeCachingFixture(): array
{
    $language = Language::factory()->createOne();

    $site = Site::factory()
        ->recycle($language)
        ->withTranslations()
        ->create();

    $type = Blueprint::factory()->page()->create([
        'key' => 'articles',
    ]);

    $createdPages = [];
    for ($index = 0; $index < 5; $index++) {
        $createdPages[] = Page::factory()
            ->site($site)
            ->type($type)
            ->published(CarbonImmutable::parse('2026-01-' . (10 + $index) . ' 10:00:00'))
            ->state([
                'created_at' => CarbonImmutable::parse('2026-01-' . (10 + $index) . ' 10:00:00'),
                'order' => 10 + $index,
            ])
            ->withTranslations($language, ['title' => 'Page ' . $index], slug: 'page-' . $index)
            ->create();
    }

    return [
        'language' => $language,
        'site' => $site,
        'type' => $type,
        'pages' => $createdPages,
    ];
}

afterEach(function (): void {
    $database = resolve(ConnectionResolverInterface::class);
    $database->disableQueryLog();
    $database->flushQueryLog();
    CapellCore::flushCache();
});

it('caches an unpaginated typed listing and avoids DB hits on the second call', function (): void {
    $fixture = makeCachingFixture();
    $database = resolve(ConnectionResolverInterface::class);

    $firstResult = PageLoader::list(new PageListingRequestData(
        language: $fixture['language'],
        site: $fixture['site'],
        pageType: 'page',
        typeKey: 'articles',
        cacheKeySuffix: 'caching-test-unpaginated',
    ));

    expect($firstResult)->toBeInstanceOf(EloquentCollection::class);

    $database->flushQueryLog();
    $database->enableQueryLog();

    $secondResult = PageLoader::list(new PageListingRequestData(
        language: $fixture['language'],
        site: $fixture['site'],
        pageType: 'page',
        typeKey: 'articles',
        cacheKeySuffix: 'caching-test-unpaginated',
    ));

    $queries = $database->getQueryLog();

    expect($queries)->toBeEmpty()
        ->and($secondResult)->toBeInstanceOf(EloquentCollection::class)
        ->and($secondResult->pluck('id')->all())->toBe($firstResult->pluck('id')->all());
});

it('caches paginated typed listings via the three-layer stack while preserving paginator behaviour', function (): void {
    $fixture = makeCachingFixture();
    $database = resolve(ConnectionResolverInterface::class);

    $firstPaginator = PageLoader::list(new PageListingRequestData(
        language: $fixture['language'],
        site: $fixture['site'],
        limit: 2,
        paginationPage: 1,
        pageType: 'page',
        typeKey: 'articles',
        withPagination: true,
        cacheKeySuffix: 'caching-test-paginated',
    ));

    expect($firstPaginator)->toBeInstanceOf(LengthAwarePaginator::class);

    $database->flushQueryLog();
    $database->enableQueryLog();

    $secondPaginator = PageLoader::list(new PageListingRequestData(
        language: $fixture['language'],
        site: $fixture['site'],
        limit: 2,
        paginationPage: 1,
        pageType: 'page',
        typeKey: 'articles',
        withPagination: true,
        cacheKeySuffix: 'caching-test-paginated',
    ));

    assert($firstPaginator instanceof LengthAwarePaginator);
    assert($secondPaginator instanceof LengthAwarePaginator);

    expect($secondPaginator)->toBeInstanceOf(LengthAwarePaginator::class)
        ->and($secondPaginator->total())->toBe($firstPaginator->total())
        ->and($secondPaginator->getCollection()->pluck('id')->all())
        ->toBe($firstPaginator->getCollection()->pluck('id')->all());
});

it('produces distinct cache entries when cacheKeySuffix differs', function (): void {
    $fixture = makeCachingFixture();
    $database = resolve(ConnectionResolverInterface::class);

    $firstResult = PageLoader::list(new PageListingRequestData(
        language: $fixture['language'],
        site: $fixture['site'],
        pageType: 'page',
        typeKey: 'articles',
        cacheKeySuffix: 'distinct-keys-test',
    ));

    $database->flushQueryLog();
    $database->enableQueryLog();

    $secondResult = PageLoader::list(new PageListingRequestData(
        language: $fixture['language'],
        site: $fixture['site'],
        pageType: 'page',
        typeKey: 'articles',
        cacheKeySuffix: 'distinct-keys-test-other',
    ));

    $queries = $database->getQueryLog();

    expect($queries)->not->toBeEmpty()
        ->and($secondResult)->toBeInstanceOf(EloquentCollection::class)
        ->and($firstResult)->toBeInstanceOf(EloquentCollection::class);
});

it('produces distinct cache entries when a real filter (withChildren) differs', function (): void {
    $fixture = makeCachingFixture();
    $database = resolve(ConnectionResolverInterface::class);

    $firstResult = PageLoader::list(new PageListingRequestData(
        language: $fixture['language'],
        site: $fixture['site'],
        pageType: 'page',
        typeKey: 'articles',
        withChildren: false,
        cacheKeySuffix: 'real-filter-test',
    ));

    expect($firstResult)->toBeInstanceOf(EloquentCollection::class);

    $database->flushQueryLog();
    $database->enableQueryLog();

    $secondResult = PageLoader::list(new PageListingRequestData(
        language: $fixture['language'],
        site: $fixture['site'],
        pageType: 'page',
        typeKey: 'articles',
        withChildren: true,
        cacheKeySuffix: 'real-filter-test',
    ));

    $queries = $database->getQueryLog();

    expect($queries)->not->toBeEmpty()
        ->and($secondResult)->toBeInstanceOf(EloquentCollection::class);
});

it('produces distinct cache entries when onlyListableTypes differs', function (): void {
    $fixture = makeCachingFixture();
    $database = resolve(ConnectionResolverInterface::class);

    $firstResult = PageLoader::list(new PageListingRequestData(
        language: $fixture['language'],
        site: $fixture['site'],
        pageType: 'page',
        typeKey: 'articles',
        onlyListableTypes: true,
        cacheKeySuffix: 'listable-filter-test',
    ));

    expect($firstResult)->toBeInstanceOf(EloquentCollection::class);

    $database->flushQueryLog();
    $database->enableQueryLog();

    $secondResult = PageLoader::list(new PageListingRequestData(
        language: $fixture['language'],
        site: $fixture['site'],
        pageType: 'page',
        typeKey: 'articles',
        onlyListableTypes: false,
        cacheKeySuffix: 'listable-filter-test',
    ));

    $queries = $database->getQueryLog();

    expect($queries)->not->toBeEmpty()
        ->and($secondResult)->toBeInstanceOf(EloquentCollection::class);
});
