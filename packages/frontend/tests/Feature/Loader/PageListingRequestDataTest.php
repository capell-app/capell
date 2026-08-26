<?php

declare(strict_types=1);

use Capell\Core\Enums\BlueprintGroupEnum;
use Capell\Core\Enums\PageOrderEnum;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;
use Capell\Frontend\Data\PageListingRequestData;
use Capell\Frontend\Data\PageListingSpec;
use Capell\Frontend\Support\Loader\PageLoader;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

afterEach(function (): void {
    $database = resolve(ConnectionResolverInterface::class);
    $database->disableQueryLog();
    $database->flushQueryLog();
});

/**
 * @return array{language: Language, site: Site, type: Blueprint, pages: Collection<int, Page>}
 */
function makePageListingRequestFixture(): array
{
    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->withTranslations()->create();
    $type = Blueprint::factory()->page()->create(['key' => 'typed-listing']);

    $pages = Page::factory()
        ->count(2)
        ->site($site)
        ->type($type)
        ->published(CarbonImmutable::parse('2026-07-29 10:00:00'))
        ->sequence(
            ['order' => 1],
            ['order' => 2],
        )
        ->withTranslations($language)
        ->create();

    return ['language' => $language, 'site' => $site, 'type' => $type, 'pages' => $pages];
}

it('keeps the 23-argument getPages adapter and maps every input to the typed request', function (): void {
    $fixture = makePageListingRequestFixture();
    $page = $fixture['pages']->firstOrFail();
    $modifyQuery = static function (Builder $query): void {
        $query->whereKey(123);
    };
    $parameters = new ReflectionMethod(PageLoader::class, 'getPages')->getParameters();

    expect(array_map(
        static fn (ReflectionParameter $parameter): string => $parameter->getName(),
        $parameters,
    ))->toBe([
        'language',
        'site',
        'page',
        'type',
        'limit',
        'paginationPage',
        'ordering',
        'pageType',
        'pageGroup',
        'typeKey',
        'optionalLanguage',
        'withChildrenCount',
        'withChildren',
        'withImage',
        'withPagination',
        'withParent',
        'withDate',
        'onlyListableTypes',
        'paginationKey',
        'cacheKeyPrepend',
        'morphModel',
        'useCache',
        'modifyQuery',
    ]);

    $captured = PageListingRequestData::fromLegacy(
        language: $fixture['language'],
        site: $fixture['site'],
        page: $page,
        type: 'children',
        limit: 12,
        paginationPage: 3,
        ordering: PageOrderEnum::Alphabetical,
        pageType: 'page',
        pageGroup: BlueprintGroupEnum::Results,
        typeKey: $fixture['type']->key,
        optionalLanguage: true,
        withChildrenCount: true,
        withChildren: true,
        withImage: true,
        withPagination: true,
        withParent: true,
        withDate: true,
        onlyListableTypes: false,
        paginationKey: 'articles',
        cacheKeyPrepend: 'legacy-filter-v1',
        morphModel: Page::class,
        useCache: false,
        modifyQuery: $modifyQuery,
    );

    expect($captured)->toBeInstanceOf(PageListingRequestData::class)
        ->and($captured->language)->toBe($fixture['language'])
        ->and($captured->site)->toBe($fixture['site'])
        ->and($captured->page)->toBe($page)
        ->and($captured->type)->toBe('children')
        ->and($captured->limit)->toBe(12)
        ->and($captured->paginationPage)->toBe(3)
        ->and($captured->ordering)->toBe(PageOrderEnum::Alphabetical)
        ->and($captured->pageType)->toBe('page')
        ->and($captured->pageGroup)->toBe(BlueprintGroupEnum::Results)
        ->and($captured->typeKey)->toBe($fixture['type']->key)
        ->and($captured->optionalLanguage)->toBeTrue()
        ->and($captured->withChildrenCount)->toBeTrue()
        ->and($captured->withChildren)->toBeTrue()
        ->and($captured->withImage)->toBeTrue()
        ->and($captured->withPagination)->toBeTrue()
        ->and($captured->withParent)->toBeTrue()
        ->and($captured->withDate)->toBeTrue()
        ->and($captured->onlyListableTypes)->toBeFalse()
        ->and($captured->paginationKey)->toBe('articles')
        ->and($captured->cacheKeySuffix)->toBe('legacy-filter-v1')
        ->and($captured->morphModel)->toBe(Page::class)
        ->and($captured->useCache)->toBeFalse()
        ->and($captured->modifyQuery)->toBe($modifyQuery);
});

it('returns ordered pages with canonical relations through the typed boundary', function (): void {
    $fixture = makePageListingRequestFixture();

    $pages = PageLoader::list(new PageListingRequestData(
        language: $fixture['language'],
        site: $fixture['site'],
        ordering: PageOrderEnum::Default,
        pageType: 'page',
        typeKey: $fixture['type']->key,
        useCache: false,
    ));

    expect($pages->pluck('id')->all())->toBe($fixture['pages']->sortBy('order')->pluck('id')->all())
        ->and($pages->firstOrFail()->getRelations())->toHaveKeys(['blueprint', 'translation', 'pageUrl']);
});

it('builds the listing spec directly from the typed request and its deterministic suffix', function (): void {
    $fixture = makePageListingRequestFixture();
    $page = $fixture['pages']->firstOrFail();
    $request = new PageListingRequestData(
        language: $fixture['language'],
        site: $fixture['site'],
        page: $page,
        type: 'siblings',
        limit: 4,
        ordering: PageOrderEnum::Oldest,
        pageType: 'page',
        pageGroup: BlueprintGroupEnum::Results,
        typeKey: $fixture['type']->key,
        optionalLanguage: true,
        onlyListableTypes: false,
        cacheKeySuffix: 'request-filter-v2',
        morphModel: Page::class,
    );

    $spec = PageListingSpec::fromRequest($request);

    expect($spec->languageId)->toBe($fixture['language']->id)
        ->and($spec->siteId)->toBe($fixture['site']->id)
        ->and($spec->pageableId)->toBe($page->id)
        ->and($spec->pageableType)->toBe($page->getMorphClass())
        ->and($spec->pageGroup)->toBe(BlueprintGroupEnum::Results->value)
        ->and($spec->cacheKeySuffix)->toBe('request-filter-v2')
        ->and($spec->toCacheKey())->toEndWith('request-filter-v2');
});

it('caches a deterministically keyed query modifier across listing and model hydration', function (): void {
    $fixture = makePageListingRequestFixture();
    $database = resolve(ConnectionResolverInterface::class);
    $calls = 0;
    $targetId = $fixture['pages']->firstOrFail()->id;
    $request = new PageListingRequestData(
        language: $fixture['language'],
        site: $fixture['site'],
        pageType: 'page',
        typeKey: $fixture['type']->key,
        cacheKeySuffix: 'page-id-' . $targetId,
        modifyQuery: function (Builder $query) use (&$calls, $targetId): void {
            $calls++;
            $query->whereKey($targetId);
        },
    );

    $first = PageLoader::list($request);

    $database->flushQueryLog();
    $database->enableQueryLog();

    $second = PageLoader::list($request);

    expect($calls)->toBe(1)
        ->and($database->getQueryLog())->toBeEmpty()
        ->and($second->pluck('id')->all())->toBe($first->pluck('id')->all());
});

it('bypasses listing and model caches for an unkeyed query modifier', function (): void {
    $fixture = makePageListingRequestFixture();
    [$firstPage, $secondPage] = $fixture['pages']->all();

    $firstResult = PageLoader::list(new PageListingRequestData(
        language: $fixture['language'],
        site: $fixture['site'],
        pageType: 'page',
        typeKey: $fixture['type']->key,
        modifyQuery: static function (Builder $query) use ($firstPage): void {
            $query->whereKey($firstPage->id);
        },
    ));

    Translation::query()
        ->where('translatable_id', $firstPage->id)
        ->where('language_id', $fixture['language']->id)
        ->update(['title' => 'Fresh from database']);

    $refreshedFirstResult = PageLoader::list(new PageListingRequestData(
        language: $fixture['language'],
        site: $fixture['site'],
        pageType: 'page',
        typeKey: $fixture['type']->key,
        modifyQuery: static function (Builder $query) use ($firstPage): void {
            $query->whereKey($firstPage->id);
        },
    ));

    $secondResult = PageLoader::list(new PageListingRequestData(
        language: $fixture['language'],
        site: $fixture['site'],
        pageType: 'page',
        typeKey: $fixture['type']->key,
        modifyQuery: static function (Builder $query) use ($secondPage): void {
            $query->whereKey($secondPage->id);
        },
    ));

    expect($firstResult->pluck('id')->all())->toBe([$firstPage->id])
        ->and($refreshedFirstResult->firstOrFail()->translation?->title)->toBe('Fresh from database')
        ->and($secondResult->pluck('id')->all())->toBe([$secondPage->id]);
});
